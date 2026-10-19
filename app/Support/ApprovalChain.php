<?php

namespace App\Support;

use App\Models\ApprovalQueue;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Support\TravelStatuses;

/**
 * Rantai approval berjenjang tunggal (single source of truth).
 *
 * Aturan level (threshold nominal):
 * - Level 1 (Line Manager = atasan langsung): selalu
 * - Level 2 (Kepala Divisi = leader departemen): cost > Rp5jt
 * - Level 3 (Finance, pool): cost > Rp15jt
 * - Level 4 (Super Admin, pengganti CFO): cost > Rp50jt
 * - Level 5 (Auto-Ticketing): otomatis, hanya pencatatan + notifikasi
 */
class ApprovalChain
{
    public const L1_THRESHOLD = 5000000;
    public const L3_THRESHOLD = 15000000;
    public const L4_THRESHOLD = 50000000;

    public const TITLES = [
        1 => 'Level 1 - Line Manager',
        2 => 'Level 2 - Kepala Divisi (HOD)',
        3 => 'Level 3 - Finance & Treasury',
        4 => 'Level 4 - CFO',
    ];

    public const ROLES = [
        1 => 'Line Manager',
        2 => 'Division Head',
        3 => 'Finance Approver',
        4 => 'Super Admin',
    ];

    /** Level-level manusia yang wajib dilewati berdasar nominal. */
    public static function levelsFor(float $amount): array
    {
        $levels = [1];
        if ($amount > self::L1_THRESHOLD) {
            $levels[] = 2;
        }
        if ($amount > self::L3_THRESHOLD) {
            $levels[] = 3;
        }
        if ($amount > self::L4_THRESHOLD) {
            $levels[] = 4;
        }

        return $levels;
    }

    /** Approver struktural untuk level (null = pool role, siapa pun ialah). */
    public static function approverFor(TravelRequest $travel, int $level): ?User
    {
        $travel->loadMissing('user.manager', 'user.department.leader');

        return match ($level) {
            1 => $travel->user?->manager,
            2 => $travel->user?->department?->leader,
            default => null,
        };
    }

    /**
     * Kandidat level beserta statusnya (dipakai / dilewati) — SATU sumber
     * kebenaran untuk plan() dan skippedSteps() supaya tidak pernah melenceng.
     *
     * Aturan lewati:
     *  1. approver struktural tidak ada (L1 tanpa atasan / L2 tanpa leader)
     *  2. approver adalah pemohon sendiri (approve sendiri dilarang decide())
     *  3. pemohon ikut pool approver level itu (Finance/CFO)
     *  4. approver sudah dipakai di level sebelumnya (anti-dobel)
     *
     * Dan jaring pengaman: bila setelah disaring tidak ada level yang tersisa,
     * tambahkan pool cadangan (Finance, atau Super Admin bila pemohonnya
     * Finance) supaya rantai tidak pernah kosong.
     *
     * @return array<int, array{level:int,title:string,role:string,approver:?User,skipped:bool,skip_reason:?string}>
     */
    private static function resolveSteps(TravelRequest $travel): array
    {
        $candidates = [];
        $usedApprovers = [];

        foreach (self::levelsFor((float) $travel->cost) as $level) {
            $approver = self::approverFor($travel, $level);
            $skip = null;

            if ($approver === null && ! in_array($level, [3, 4], true)) {
                $skip = 'Tidak ada approver struktural';
            } elseif ($approver !== null && (int) $approver->id === (int) $travel->user_id) {
                $skip = 'Pemohon adalah approver struktural level ini';
            } elseif ($approver === null && in_array($level, [3, 4], true)
                && ($travel->user?->role ?? null) === self::ROLES[$level]) {
                // Pool (Finance / CFO): kalau pemohon sendiri anggota pool itu,
                // dia tidak bisa memutus requestnya sendiri (decide() menolak),
                // dan bila pool cuma beranggotakan 1 orang rantainya macet.
                $skip = 'Pemohon termasuk anggota pool approver level ini';
            } elseif ($approver !== null && isset($usedApprovers[(int) $approver->id])) {
                // Orang yang sama tidak mengulang di level berikutnya — mis. Line
                // Manager yang atasan langsungnya justru Kepala Divisi.
                $skip = 'Sudah menjadi approver pada level sebelumnya';
            }

            if ($skip === null && $approver !== null) {
                $usedApprovers[(int) $approver->id] = true;
            }

            $candidates[] = [
                'level' => $level,
                'title' => self::TITLES[$level],
                'role' => self::ROLES[$level],
                'approver' => $approver,
                'skipped' => $skip !== null,
                'skip_reason' => $skip,
                'fallback' => false,
            ];
        }

        // Jaring pengaman: rantai tidak boleh kosong. Tanpa ini pengajuan
        // kecil dari user tanpa atasan akan Approved otomatis tanpa pernah
        // dilihat manusia, dan pemohon yang jadi approver himselfnya bikin
        // rantai macet.
        if (array_filter($candidates, fn ($c) => ! $c['skipped']) === []) {
            // Finance dulu; kalau pemohonnya sendiri Finance, pakai Super Admin.
            $order = ($travel->user?->role === 'Finance Approver') ? [4, 3] : [3, 4];
            foreach ($order as $level) {
                $candidates[] = [
                    'level' => $level,
                    'title' => self::TITLES[$level],
                    'role' => self::ROLES[$level],
                    'approver' => null,
                    'skipped' => false,
                    'skip_reason' => null,
                    'fallback' => true,
                ];
                break;
            }
        }

        return $candidates;
    }

    /**
     * Rencana rantai yang akan dijalankan. Label ("1 of 2") dihitung dari
     * jumlah level yang benar-benar dipakai, jadi selalu konsisten.
     *
     * @return array<int, array{level:int,label:string,title:string,role:string,approver:?User,skipped:bool,skip_reason:?string,sequence:int}>
     */
    public static function plan(TravelRequest $travel): array
    {
        $steps = array_values(array_filter(self::resolveSteps($travel), fn ($c) => ! $c['skipped']));
        $total = count($steps);

        return array_map(function (array $c, int $i) use ($total): array {
            // Label cadangan tetap diawali angka karena User::canDecideOwn()
            // mem-parse `str($queue->level)->before(' ')` untuk menentukan level.
            // Label polos seperti "Cadangan Finance" akan ter-parse jadi 0 dan
            // membuat pool tidak bisa diputus.
            $label = $c['fallback']
                ? $c['level'].' ('.($c['level'] === 4 ? 'Cadangan Super Admin' : 'Cadangan Finance').')'
                : $c['level'].' of '.$total;

            return $c + [
                'label' => $label,
                'sequence' => $i + 1,
            ];
        }, $steps, array_keys($steps));
    }

    /**
     * Level yang dilewati beserta alasannya — untuk audit + notifikasi.
     *
     * @return array<int, array{level:int,title:string,reason:string}>
     */
    public static function skippedSteps(TravelRequest $travel): array
    {
        return array_values(array_map(
            fn (array $c) => ['level' => $c['level'], 'title' => $c['title'], 'reason' => (string) $c['skip_reason']],
            array_filter(self::resolveSteps($travel), fn ($c) => $c['skipped'])
        ));
    }

    /** Bentuk baris antrean untuk satu TR baru + notifikasi ke approver pertama. */
    public static function build(TravelRequest $travel, Request $request): void
    {
        $plan = self::plan($travel);
        $firstActive = false;

        foreach ($plan as $i => $step) {
            $active = false;
            if (! $firstActive) {
                // resolveSteps() sudah menjamin ada minimal satu approver
                // (pool L3/L4 bila tidak ada approver struktural yang sah),
                // jadi cabang auto-pass ini kini hanya jaring pengaman.
                if ($step['approver'] || in_array($step['level'], [3, 4], true)) {
                    $active = true;
                    $firstActive = true;
                } else {
                    Audits::record(
                        'travel.autopass',
                        $travel,
                        [],
                        ['level' => $step['label'], 'reason' => 'Tidak ada approver struktural'],
                        $request
                    );
                    continue;
                }
            }

            ApprovalQueue::create([
                'travel_request_id' => $travel->id,
                'type' => 'Travel',
                'level' => $step['label'],
                'sequence' => $step['sequence'],
                'approver_id' => $step['approver']?->id,
                'status' => $active ? TravelStatuses::Q_PENDING : TravelStatuses::Q_QUEUED,
                'sla_due_at' => $active ? now()->addHours(24) : null,
                'amount' => $travel->cost,
                'justification' => $travel->purpose,
            ]);

            if ($active) {
                self::notifyApprovers($travel, $step);
            }
        }

        Audits::record('travel.submit', $travel, [], $travel->only('status', 'request_no'), $request);

        // Beri tahu Super Admin setiap level yang dilewati — rantai yang_skip
        // adalah keputusan sistem, harus terlihat oleh manusia.
        $skipped = self::skippedSteps($travel);
        if ($skipped !== []) {
            foreach ($skipped as $s) {
                Audits::record(
                    'travel.autopass',
                    $travel,
                    [],
                    ['level' => self::TITLES[$s['level']], 'reason' => $s['reason']],
                    $request
                );
            }
            self::notifySuperAdmins(
                $travel,
                'Ada level approval yang dilewati otomatis',
                collect($skipped)->map(fn ($s) => $s['title'].' — '.$s['reason'])->implode('; ').'.'
            );
        }

        if (! $firstActive) {
            // Jaring pengaman: seluruh rantai auto-pass.
            $travel->update(['status' => TravelStatuses::TR_APPROVED, 'policy_status' => TravelStatuses::POLICY_APPROVED]);
            Audits::record('travel.autopass-all', $travel, [], $travel->only('status'), $request);
            if ($travel->user) {
                Notify::user(
                    $travel->user,
                    "Pengajuan {$travel->request_no} disetujui otomatis",
                    'Tidak ada approver struktural yang wajib dilewati, pengajuan disetujui otomatis.',
                    'Approval', 'approval', $travel->request_no
                );
            }
        }
    }

    /**
     * Apakah pemohon berada di luar jangkauan visibilitas user ini?
     * null dari visibleUserIds() berarti tanpa pembatasan (Finance, Super Admin).
     */
    private static function isOutOfScope(User $me, int $requesterId): bool
    {
        $ids = $me->visibleUserIds();

        return $ids !== null && ! in_array($requesterId, array_map('intval', $ids), true);
    }

    /**
     * Batch approve dengan semantik serba gagal (all-or-nothing).
     *
     * Dua lapis perlindungan:
     * 1. Pre-filter — item yang memang tidak boleh diputus role ini ditolak lebih
     *    dulu, sebelum ada efek samping apa pun (notifikasi/email/audit).
     * 2. DB::transaction — bila satu item tetap gagal di tengah jalan, seluruh
     *    batch dibatalkan. Karena QUEUE_CONNECTION=database, job email di tabel
     *    `jobs` ikut rollback bersama transaksi.
     *
     * @param  iterable<ApprovalQueue>  $queues  hanya baris berstatus pending
     * @return array{ok: bool, message: string, count?: int, failed?: array<int, array<string, mixed>>}
     */
    public static function batchDecide(iterable $queues, User $me, Request $request, string $successTemplate): array
    {
        $queues = collect($queues)->values();

        // 1. Pre-filter wewenang (level + delegasi), larangan menyetujui milik sendiri,
        //    danjskop wilayah kerja — semuanya disaring sebelum ada efek samping.
        $failed = $queues->reject(function (ApprovalQueue $q) use ($me) {
            $requesterId = $q->travelRequest !== null ? (int) $q->travelRequest->user_id : null;

            return $me->canDecide($q)
                && $requesterId !== $me->id
                && ($requesterId === null || ! self::isOutOfScope($me, $requesterId));
        })->map(function (ApprovalQueue $q) use ($me) {
            $requesterId = $q->travelRequest !== null ? (int) $q->travelRequest->user_id : null;
            $isOwn = $requesterId === (int) $me->id;

            return [
                'id' => $q->id,
                'level' => $q->level,
                'reason' => $isOwn
                    ? 'Tidak boleh menyetujui pengajuan milik sendiri.'
                    : (($requesterId !== null && self::isOutOfScope($me, $requesterId))
                        ? 'Pengajuan ini berada di luar wilayah kerja Anda.'
                        : "Level {$q->level} bukan wewenang role Anda."),
            ];
        })->values()->all();

        if ($failed !== []) {
            return [
                'ok' => false,
                'message' => count($failed).' dari '.$queues->count().' pengajuan tidak dapat diproses oleh role Anda. Tidak ada perubahan yang disimpan.',
                'failed' => $failed,
            ];
        }

        // 2. Proses dalam satu transaksi.
        try {
            $count = DB::transaction(function () use ($queues, $me, $request) {
                foreach ($queues as $q) {
                    self::decide($q, $me, 'approve', null, $request);
                }

                return $queues->count();
            });
        } catch (HttpException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => str_replace('{count}', (string) $count, $successTemplate), 'count' => $count];
    }

    /**
     * Apakah user ini berwenang memutus baris antrean ini?
     *
     * Sumber tunggal kebenaran "giliran siapa". Dipakai dua pihak:
     *  - decide()          → melempar HttpException dengan status yang sama
     *  - appendDecisionMeta() → ekspos can_decide ke FE
     *
     * Tanpa titik ini FE hanya bisa mengecek role, sehingga menampilkan tombol
     * approve pada baris milik approver lain (klik → 403).
     *
     * @return array{ok:bool, status:int, reason:?string}
     */
    public static function eligibility(ApprovalQueue $queue, User $me): array
    {
        if ($queue->status !== TravelStatuses::Q_PENDING) {
            return ['ok' => false, 'status' => 409, 'reason' => 'Antrean ini sudah diputus sebelumnya.'];
        }
        if (! $me->canDecide($queue)) {
            return ['ok' => false, 'status' => 403, 'reason' => "Akses ditolak: level {$queue->level} bukan wewenang role Anda."];
        }
        if (! self::isDesignated($queue, $me) && ! $me->isSuperAdmin()) {
            return ['ok' => false, 'status' => 403, 'reason' => 'Hanya approver yang dituju (atau Plt-nya) yang boleh memutus antrean ini.'];
        }

        $travel = $queue->travelRequest;
        if ($travel && (int) $travel->user_id === (int) $me->id) {
            return ['ok' => false, 'status' => 403, 'reason' => 'Tidak boleh menyetujui pengajuan milik sendiri.'];
        }

        // Skop wilayah kerja: pemohon harus berada dalam jangkauan visibilitas
        // approver. Tanpa ini siapa pun ber-role approver bisa memutus pengajuan
        // level-nya di seluruh perusahaan, bukan hanya timnya sendiri
        // (Employee → diri sendiri, LM → bawahan, DH → departemennya,
        //  Finance & Super Admin → semua).
        if ($travel && self::isOutOfScope($me, (int) $travel->user_id)) {
            return ['ok' => false, 'status' => 403, 'reason' => 'Pengajuan ini berada di luar wilayah kerja Anda.'];
        }

        return ['ok' => true, 'status' => 200, 'reason' => null];
    }

    /**
     * Tempelkan atribut turunan per baris antrean untuk ditampilkan FE:
     * can_decide, block_reason, decided_by_me, my_decision, waiting_for.
     *
     * Memakai eligibility() sehingga yang tampil di UI persis sama dengan
     * yang akan dievaluasi decide() — tidak ada drift UI vs backend.
     */
    public static function appendDecisionMeta(ApprovalQueue $queue, User $me): ApprovalQueue
    {
        $e = self::eligibility($queue, $me);
        $decidedByMe = $queue->decided_by !== null && (int) $queue->decided_by === (int) $me->id;

        $queue->setAttribute('can_decide', $e['ok']);
        $queue->setAttribute('block_reason', $e['ok'] ? null : $e['reason']);
        $queue->setAttribute('decided_by_me', $decidedByMe);
        $queue->setAttribute(
            'my_decision',
            $decidedByMe ? $queue->status : null
        );
        // Approver tujuan; null untuk baris pool (Finance/CFO) atau yang sudah selesai.
        $queue->setAttribute(
            'waiting_for',
            $queue->status === TravelStatuses::Q_PENDING ? $queue->approver?->name : null
        );

        return $queue;
    }

    /**
     * Putus satu baris antrean (approve/reject) + majukan rantai.
     * Melempar HttpException (403/409) bila tidak eligible.
     *
     * @return array{message: string, finalized: bool}
     */
    public static function decide(ApprovalQueue $queue, User $me, string $action, ?string $note, Request $request): array
    {
        $eligibility = self::eligibility($queue, $me);
        if (! $eligibility['ok']) {
            throw new HttpException($eligibility['status'], $eligibility['reason']);
        }

        $travel = $queue->travelRequest;

        $via = $me->delegationFor($queue);
        $approved = $action === 'approve';
        $queue->update([
            'status' => $approved ? TravelStatuses::Q_APPROVED : TravelStatuses::Q_REJECTED,
            'decided_by' => $me->id,
            'on_behalf_of' => $via?->delegator_id,
            'decided_at' => now(),
            'note' => $note,
        ]);

        if (! $approved) {
            // Reject menghentikan seluruh rantai.
            ApprovalQueue::where('travel_request_id', $queue->travel_request_id)
                ->where('status', TravelStatuses::Q_QUEUED)
                ->update(['status' => TravelStatuses::Q_CANCELLED, 'note' => 'Dibatalkan otomatis (rantai dihentikan).']);
            if ($travel) {
                self::finalize($travel, false, $me, $via, $note, $request);
            }

            return ['message' => 'Keputusan rejected berhasil dikirim.', 'finalized' => true];
        }

        $next = ApprovalQueue::where('travel_request_id', $queue->travel_request_id)
            ->where('status', TravelStatuses::Q_QUEUED)
            ->orderBy('sequence')
            ->first();

        if ($next) {
            $next->update(['status' => TravelStatuses::Q_PENDING, 'sla_due_at' => now()->addHours(24)]);
            $travel?->update(['status' => TravelStatuses::TR_IN_REVIEW]);
            if ($travel) {
                $step = self::stepOf($travel, $next);
                if ($step) {
                    self::notifyApprovers($travel, $step);
                }
            }

            return ['message' => "Level {$queue->level} disetujui, diteruskan ke level berikut.", 'finalized' => false];
        }

        if ($travel) {
            self::finalize($travel, true, $me, $via, $note, $request);
        }

        return ['message' => 'Keputusan approved berhasil dikirim.', 'finalized' => true];
    }

    /**
     * policy_status saat finalisasi: badge deviasi dari pengajuan dipertahankan
     * ("Policy Approved · Deviasi 12%") agar jejak kepatuhan tidak hilang;
     * nilai lain (termasuk null) berperilaku seperti sebelumnya.
     */
    protected static function approvedPolicyStatus(?string $current): string
    {
        if (is_string($current) && str_starts_with($current, 'Deviasi')) {
            return "Policy Approved · {$current}";
        }

        return TravelStatuses::POLICY_APPROVED;
    }

    /** Finalisasi TR + baris Level 5 otomatis + notifikasi pemohon. */
    protected static function finalize(TravelRequest $travel, bool $approved, User $me, $via, ?string $note, Request $request): void
    {
        $oldTravel = $travel->only('status', 'policy_status');
        $travel->update([
            'status' => $approved ? TravelStatuses::TR_APPROVED : TravelStatuses::TR_REJECTED,
            'policy_status' => $approved ? self::approvedPolicyStatus($travel->policy_status) : TravelStatuses::POLICY_REJECTED,
        ]);

        $actorNote = $via ? " oleh {$me->name} (Plt a/n {$via->delegator?->name})" : " oleh {$me->name}";
        Audits::record(
            $approved ? 'travel.approve' : 'travel.reject',
            $travel,
            $oldTravel,
            array_merge($travel->only('status', 'policy_status'), ['decided_by' => $me->id, 'on_behalf_of' => $via?->delegator_id]),
            $request
        );

        if ($approved) {
            // Level 5 — Auto-Ticketing: pencatatan otomatis, tiket fisik diterbitkan travel desk.
            ApprovalQueue::create([
                'travel_request_id' => $travel->id,
                'type' => 'Travel',
                'level' => 'Auto',
                'sequence' => 99,
                'status' => TravelStatuses::Q_APPROVED,
                'decided_at' => now(),
                'note' => 'Auto-Ticketing: diteruskan ke travel desk.',
                'amount' => $travel->cost,
                'justification' => $travel->purpose,
            ]);
        }

        $requester = $travel->user;
        if ($requester) {
            Notify::user(
                $requester,
                $approved ? "Pengajuan {$travel->request_no} disetujui" : "Pengajuan {$travel->request_no} ditolak",
                ($note ?? ($approved ? 'Pengajuan Anda telah disetujui.' : 'Pengajuan Anda ditolak. Silakan perbaiki dokumen.')).$actorNote.'.',
                'Approval', 'approval', $travel->request_no,
                $approved ? "Pengajuan {$travel->request_no} disetujui" : "Pengajuan {$travel->request_no} ditolak"
            );
        }
    }

    /** Apakah user ini yang dituju baris antrean (atau Plt-nya)? */
    protected static function isDesignated(ApprovalQueue $queue, User $me): bool
    {
        if ($queue->approver_id === null) {
            return true; // pool role (finance/SA)
        }
        if ((int) $queue->approver_id === (int) $me->id) {
            return true;
        }
        $via = $me->delegationFor($queue);

        return $via && (int) $via->delegator_id === (int) $queue->approver_id;
    }

    protected static function stepOf(TravelRequest $travel, ApprovalQueue $queue): ?array
    {
        foreach (self::plan($travel) as $step) {
            if ($step['label'] === $queue->level) {
                return $step;
            }
        }

        return null;
    }

    /** Kirim notifikasi ke approver yang dituju (perorangan / pool finance / SA). */
    protected static function notifyApprovers(TravelRequest $travel, array $step): void
    {
        $targets = collect();
        if ($step['approver']) {
            $targets->push($step['approver']);
        } elseif ($step['level'] === 3) {
            $targets = User::where('role', 'Finance Approver')->where('status', 'Aktif')->get();
        } elseif ($step['level'] === 4) {
            $targets = User::where('role', 'Super Admin')->where('status', 'Aktif')->get();
        }

        foreach ($targets as $user) {
            Notify::user(
                $user,
                "Antrean {$step['label']}: {$travel->request_no} menunggu keputusan Anda",
                "{$travel->user?->name} mengajukan {$travel->route} (estimasi Rp ".number_format((float) $travel->cost, 0, ',', '.').').',
                'Approval', 'approval', $travel->request_no,
                "Antrean {$travel->request_no} menunggu keputusan"
            );
        }
    }

    /**
     * Kabari Super Admin saat sistem melewati level approval secara otomatis.
     * Level yang dilewati adalah keputusan sistem, jadi harus terlihat manusia.
     */
    protected static function notifySuperAdmins(TravelRequest $travel, string $title, string $message): void
    {
        foreach (User::where('role', 'Super Admin')->where('status', 'Aktif')->get() as $sa) {
            Notify::user(
                $sa,
                "{$title}: {$travel->request_no}",
                $message,
                'Approval', 'approval', $travel->request_no,
                "{$travel->request_no}: level approval dilewati otomatis"
            );
        }
    }
}
