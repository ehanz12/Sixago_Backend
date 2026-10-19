<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalQueue;
use App\Models\TravelRequest;
use Illuminate\Http\Request;
use App\Support\TravelStatuses;

class TravelRequestController extends Controller
{
    /** Pesan penolakan seragam saat estimasi melebihi plafon policy grade. */
    private function overPlafonMessage(\App\Models\User $owner, float $cost, array $limit): string
    {
        return 'Estimasi biaya Rp '.number_format($cost, 0, ',', '.')
            .' melebihi plafon policy '.$limit['policy']->code
            .' untuk grade '.$owner->grade
            .' (maks Rp '.number_format($limit['total'], 0, ',', '.').').';
    }

    /** Skop visibilitas requester sesuai role user login. */
    private function scopeVisible($query, Request $request)
    {
        $ids = $request->user()->visibleUserIds();
        if ($ids !== null) {
            $query->whereIn('user_id', $ids);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->scopeVisible(TravelRequest::with(['user.department', 'project']), $request);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($w) use ($q) {
                $w->where('request_no', 'like', "%{$q}%")
                    ->orWhere('route', 'like', "%{$q}%")
                    ->orWhere('purpose', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('project', fn ($p) => $p->where('name', 'like', "%{$q}%"));
            });
        }
        if ($request->filled('department')) {
            $query->whereHas('user.department', fn ($d) => $d->where('name', 'like', "%{$request->department}%"));
        }
        if ($request->filled('project')) {
            $query->whereHas('project', fn ($p) => $p->where('name', 'like', "%{$request->project}%"));
        }
        if ($request->filled('start_from')) {
            $query->whereDate('start_date', '>=', $request->start_from);
        }
        if ($request->filled('start_to')) {
            $query->whereDate('start_date', '<=', $request->start_to);
        }

        $perPage = (int) $request->input('per_page', 10);

        return response()->json($query->latest()->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'project_id' => 'required|exists:projects,id',
            'route' => 'required|string|max:255',
            'transport' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'cost' => 'required|numeric|min:0',
            'purpose' => 'nullable|string',
        ]);

        // Non-SA hanya boleh mengajukan untuk diri sendiri
        if (! $request->user()->isSuperAdmin() && (int) $data['user_id'] !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        // Normalisasi rute: cegah varian spasi/kapitalisasi ganda (sumber mojibake kolom route).
        $data['route'] = strtoupper(preg_replace('/\s+/', ' ', trim($data['route'])));

        // Pemilik pengajuan (bukan user login) yang menentukan grade → policy
        // berlaku. Penting saat Super Admin mengajukan atas nama orang lain.
        $owner = \App\Models\User::findOrFail($data['user_id']);
        $days = \App\Support\PolicyCheck::tripDays($data['start_date'], $data['end_date']);

        // Pembatas plafon: estimasi biaya tidak boleh melebihi total plafon
        // policy grade pemohon (di-set server, bukan manual). Tanpa policy
        // yang cocok → tanpa pembatas.
        $limit = \App\Support\PolicyCheck::resolve($owner, $days);
        if ($limit !== null && (float) $data['cost'] > $limit['total']) {
            return response()->json([
                'message' => $this->overPlafonMessage($owner, (float) $data['cost'], $limit),
                'errors' => ['cost' => [
                    'Estimasi biaya melebihi plafon policy '.$limit['policy']->code
                    .' (maks Rp '.number_format($limit['total'], 0, ',', '.').').',
                ]],
                'policy' => ['policy_code' => $limit['policy']->code, 'limit' => $limit['total']],
            ], 422);
        }

        $data['request_no'] = 'TR-' . now()->format('Y') . '-' . str_pad((string) (TravelRequest::max('id') + 1), 3, '0', STR_PAD_LEFT);
        $data['status'] = TravelStatuses::TR_SUBMITTED;
        // Durasi dihitung server dari tanggal; nilai kiriman client diabaikan.
        $data['duration'] = TravelRequest::durationLabel($data['start_date'], $data['end_date']);

        // Badge kepatuhan policy (PolicyCheck): 'Sesuai' / 'Deviasi N%' / null
        // bila tak ada policy yang cocok. Tidak mengubah rantai approval.
        $check = \App\Support\PolicyCheck::check($owner, (float) $data['cost'], $days);
        $data['policy_status'] = $check['status'] ?? null;
        $data['policy_code'] = $check['policy_code'] ?? null;

        $travel = TravelRequest::create($data);

        // Bentuk rantai approval berjenjang (L1 selalu, L2/L3/L4 ikut threshold).
        \App\Support\ApprovalChain::build($travel, $request);

        return response()->json($travel->fresh()->load(['user', 'project']), 201);
    }

    public function show(Request $request, TravelRequest $travelRequest)
    {
        $ids = $request->user()->visibleUserIds();
        if ($ids !== null && ! in_array($travelRequest->user_id, $ids, true)) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        return response()->json($travelRequest->load([
            'user.department', 'user.manager', 'project.pm', 'approvals.approver', 'approvals.decidedBy', 'approvals.onBehalfOf', 'advances', 'reimbursements', 'documents',
        ]));
    }

    /** Lookup by nomor dokumen (link FE memakai request_no, mis. TR-2025-104). */
    public function byRequestNo(Request $request, string $requestNo)
    {
        $travel = TravelRequest::with([
            'user.department', 'user.manager', 'project.pm', 'approvals.approver', 'approvals.decidedBy', 'approvals.onBehalfOf', 'advances', 'reimbursements', 'documents',
        ])->where('request_no', $requestNo)->firstOrFail();

        $ids = $request->user()->visibleUserIds();
        if ($ids !== null && ! in_array($travel->user_id, $ids, true)) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        // can_decide/decided_by_me/waiting_for per baris — sumbernya
        // ApprovalChain::eligibility(), sama persis dengan yang dipakai decide().
        $me = $request->user();
        foreach ($travel->approvals as $queue) {
            \App\Support\ApprovalChain::appendDecisionMeta($queue, $me);
        }

        return response()->json($travel);
    }

    public function update(Request $request, TravelRequest $travelRequest)
    {
        // Ubah data: pemilik atau Super Admin (keputusan status lewat endpoint decide)
        if (! $request->user()->isSuperAdmin() && $travelRequest->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        $data = $request->validate([
            'route' => 'sometimes|string|max:255',
            'transport' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
            'cost' => 'sometimes|numeric|min:0',
            'purpose' => 'nullable|string',
            'status' => 'sometimes|string|max:50',
            'policy_status' => 'nullable|string|max:100',
            'project_id' => 'sometimes|exists:projects,id',
        ]);

        // policy_status milik sistem (PolicyCheck/finalize) — tidak bisa ditulis manual.
        if (array_key_exists('policy_status', $data)) {
            return response()->json(['message' => 'policy_status dihitung otomatis oleh sistem.'], 403);
        }

        if (array_key_exists('route', $data)) {
            $data['route'] = strtoupper(preg_replace('/\s+/', ' ', trim($data['route'])));
        }

        // Pembatas plafon: bila biaya/tanggal berubah, nominal efektif (nilai
        // lama + perubahan) tidak boleh melebihi total plafon policy grade
        // pemohon. Cegah bypass lewat edit setelah pengajuan dibuat.
        if (array_key_exists('cost', $data) || array_key_exists('start_date', $data) || array_key_exists('end_date', $data)) {
            $effectiveCost = (float) ($data['cost'] ?? $travelRequest->cost);
            $effectiveStart = (string) ($data['start_date'] ?? $travelRequest->start_date);
            $effectiveEnd = (string) ($data['end_date'] ?? $travelRequest->end_date);
            $days = \App\Support\PolicyCheck::tripDays($effectiveStart, $effectiveEnd);
            $limit = \App\Support\PolicyCheck::resolve($travelRequest->user, $days);
            if ($limit !== null && $effectiveCost > $limit['total']) {
                return response()->json([
                    'message' => $this->overPlafonMessage($travelRequest->user, $effectiveCost, $limit),
                    'errors' => ['cost' => [
                        'Estimasi biaya melebihi plafon policy '.$limit['policy']->code
                        .' (maks Rp '.number_format($limit['total'], 0, ',', '.').').',
                    ]],
                    'policy' => ['policy_code' => $limit['policy']->code, 'limit' => $limit['total']],
                ], 422);
            }
        }

        // Perubahan status via PUT dibatasi penarikan pra-approval oleh pemohon:
        // hanya 'Cancelled', hanya saat masih Submitted dan belum ada baris
        // antrean yang diputus. Selain itu status hanya berubah lewat decide().
        if (array_key_exists('status', $data)) {
            if ($data['status'] !== TravelStatuses::TR_CANCELLED) {
                return response()->json(['message' => 'Status hanya dapat diubah melalui jalur approval (decide).'], 403);
            }
            $hasDecided = $travelRequest->approvals()
                ->whereIn('status', [TravelStatuses::Q_APPROVED, TravelStatuses::Q_REJECTED])->exists();
            if ($travelRequest->status !== TravelStatuses::TR_SUBMITTED || $hasDecided) {
                return response()->json(['message' => 'Tidak dapat dibatalkan: pengajuan sudah memiliki keputusan approval.'], 409);
            }
            // Bekukan rantai agar baris pending/queued tidak bisa "menghidupkan"
            // kembali pengajuan yang sudah dibatalkan.
            $travelRequest->approvals()->whereIn('status', [TravelStatuses::Q_PENDING, TravelStatuses::Q_QUEUED])
                ->update(['status' => TravelStatuses::Q_CANCELLED, 'note' => 'Dibatalkan oleh pemohon sebelum ada keputusan.']);
        }

        // Durasi selalu dihitung ulang dari tanggal efektif (nilai client diabaikan).
        $data['duration'] = TravelRequest::durationLabel(
            (string) ($data['start_date'] ?? $travelRequest->start_date),
            (string) ($data['end_date'] ?? $travelRequest->end_date)
        );

        $old = $travelRequest->only(array_keys($data));
        $travelRequest->update($data);

        // Badge kepatuhan mengikuti angka terbaru selama pengajuan belum final.
        // TR final (Approved/Rejected) dibekukan sebagai sejarah.
        if (! in_array($travelRequest->status, [TravelStatuses::TR_APPROVED, TravelStatuses::TR_REJECTED], true)
            && (array_key_exists('cost', $data) || array_key_exists('start_date', $data) || array_key_exists('end_date', $data))) {
            $fresh = $travelRequest->fresh();
            $days = \App\Support\PolicyCheck::tripDays($fresh->start_date, $fresh->end_date);
            $check = \App\Support\PolicyCheck::check($fresh->user, (float) $fresh->cost, $days);
            $fresh->update([
                'policy_status' => $check['status'] ?? null,
                'policy_code' => $check['policy_code'] ?? null,
            ]);
            $travelRequest = $fresh;
        }

        // Jejak audit perubahan field (meniru DepartmentController@update).
        \App\Support\Audits::record(
            'travel.update', $travelRequest,
            $old, $travelRequest->only(array_keys($data)), $request
        );

        return response()->json($travelRequest->load(['user', 'project']));
    }

    public function destroy(Request $request, TravelRequest $travelRequest)
    {
        if (! $request->user()->isSuperAdmin() && $travelRequest->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        if ($travelRequest->approvals()->exists() || $travelRequest->advances()->exists()
            || $travelRequest->reimbursements()->exists() || $travelRequest->documents()->exists()) {
            return response()->json(['message' => 'Tidak dapat dihapus: travel request masih memiliki approval, uang muka, reimbursement, atau dokumen. Batalkan statusnya sebagai gantinya.'], 409);
        }

        $travelRequest->delete();

        return response()->noContent();
    }

    /** Batch approve — dipakai tombol "Batch Approve" di tabel travel-requests. */
    public function batchApprove(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'exists:travel_requests,id']);

        // Batch = majukan SATU tahap aktif per TR via rantai berjenjang.
        $queues = ApprovalQueue::with('travelRequest')->whereIn('travel_request_id', $data['ids'])
            ->where('status', 'pending')->get();

        $result = \App\Support\ApprovalChain::batchDecide(
            $queues,
            $request->user(),
            $request,
            '{count} request berhasil diproses (satu tahap per request).'
        );

        return response()->json(
            array_filter([
                'message' => $result['message'],
                'count' => $result['count'] ?? 0,
                'failed' => $result['failed'] ?? null,
            ], fn ($v) => $v !== null),
            $result['ok'] ? 200 : 422
        );
    }

    /** Ringkasan KPI + count per status — dipakai status tabs & KPI cards. */
    public function summary(Request $request)
    {
        $base = TravelRequest::query();
        $ids = $request->user()->visibleUserIds();
        if ($ids !== null) {
            $base->whereIn('user_id', $ids);
        }

        return response()->json([
            'total' => (clone $base)->count(),
            'by_status' => (clone $base)->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status'),
            'pending_approval' => (clone $base)->whereIn('status', [TravelStatuses::TR_SUBMITTED, TravelStatuses::TR_IN_REVIEW])->count(),
            'approved' => (clone $base)->where('status', TravelStatuses::TR_APPROVED)->count(),
            'total_commitment' => (clone $base)->sum('cost'),
        ]);
    }
}
