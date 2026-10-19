<?php

namespace App\Support;

use App\Models\Advance;
use App\Models\Reimbursement;

/**
 * Alur keputusan finance untuk uang muka & reimbursement.
 *
 * Satu-satunya definisi transisi yang sah. Tanpa ini, controller menerima
 * aksi apa pun dari status berapa pun: memverifikasi klaim yang sudah
 * terverifikasi diam-diam jadi no-op (layar tidak berubah, padahal
 * notifikasi terkirim ulang ke pemohon), dan klaim bisa dimundurkan dari
 * "Disetujui" ke "Menunggu Verifikasi".
 *
 * FE tidak pernah menebak alur ini — dia membaca flag can_* yang
 * dihitung dari kelas ini, bukan dari teks label.
 */
final class FinanceFlow
{
    /**
     * Reimbursement: aksi => [status_sekarang => status_berikutnya].
     *
     * Menunggu Audit → (Verifikasi) → Menunggu Verifikasi Finance
     *                 → (Setujui Settlement) → Disetujui
     * Keduanya bisa ditolak kapan saja sebelum selesai.
     *
     * @var array<string, array<string, string>>
     */
    public const REIMBURSEMENT = [
        'verify' => [
            TravelStatuses::REIM_AUDIT => TravelStatuses::REIM_VERIFY,
        ],
        'settlement' => [
            TravelStatuses::REIM_VERIFY => TravelStatuses::REIM_APPROVED,
        ],
        'reject' => [
            TravelStatuses::REIM_AUDIT => TravelStatuses::REIM_REJECTED,
            TravelStatuses::REIM_VERIFY => TravelStatuses::REIM_REJECTED,
        ],
    ];

    /**
     * Uang muka: aksi => [status_sekarang => status_berikutnya].
     *
     * Pending Finance → (Setujui) → Approved-Ready to Pay → (Disburse) → Paid
     *
     * @var array<string, array<string, string>>
     */
    public const ADVANCE = [
        'approve' => [
            TravelStatuses::ADV_PENDING => TravelStatuses::ADV_READY,
        ],
        'reject' => [
            TravelStatuses::ADV_PENDING => TravelStatuses::ADV_REJECTED,
        ],
        'disburse' => [
            TravelStatuses::ADV_READY => TravelStatuses::ADV_PAID,
        ],
    ];

    /**
     * Status tujuan untuk sebuah aksi, atau null bila transisi tidak sah.
     */
    public static function nextStatus(string $action, string $current, bool $isAdvance = false): ?string
    {
        return ($isAdvance ? self::ADVANCE : self::REIMBURSEMENT)[$action][$current] ?? null;
    }

    /** Aksi yang masih mungkin dilakukan pada status tertentu. */
    public static function allowedActions(string $status, bool $isAdvance = false): array
    {
        $map = $isAdvance ? self::ADVANCE : self::REIMBURSEMENT;
        $out = [];
        foreach ($map as $action => $transitions) {
            if (isset($transitions[$status])) {
                $out[] = $action;
            }
        }

        return $out;
    }

    /**
     * Flag siap pakai untuk UI. FE memakai ini supaya tidak pernah
     * menyimpulkan "giliran saya" dari teks label.
     *
     * @return array{can_verify:bool, can_settle:bool, can_disburse:bool, block_reason:?string}
     */
    public static function flagsFor(string $status, bool $isAdvance = false): array
    {
        $canVerify = $isAdvance
            ? in_array('approve', self::allowedActions($status, true), true)
            : in_array('verify', self::allowedActions($status), true);

        $canSettle = ! $isAdvance && in_array('settlement', self::allowedActions($status), true);
        $canDisburse = $isAdvance && in_array('disburse', self::allowedActions($status, true), true);

        return [
            'can_verify' => $canVerify,
            'can_settle' => $canSettle,
            'can_disburse' => $canDisburse,
            'block_reason' => self::blockReason($status, $isAdvance),
        ];
    }

    /**
     * Alasan aksi berikutnya tidak tersedia — untuk tooltip/badge di UI.
     */
    public static function blockReason(string $status, bool $isAdvance = false): ?string
    {
        $actions = self::allowedActions($status, $isAdvance);
        if ($actions !== []) {
            return null;
        }

        return match ($status) {
            TravelStatuses::REIM_APPROVED => 'Klaim sudah disettle — tidak ada aksi lagi.',
            TravelStatuses::REIM_REJECTED => 'Klaim ditolak — perlu diperbaiki pemohon.',
            TravelStatuses::ADV_PAID => 'Dana sudah dicairkan.',
            TravelStatuses::ADV_REJECTED => 'Pengajuan uang muka ditolak.',
            default => 'Tidak ada aksi yang tersedia pada status ini.',
        };
    }

    /** Pesan 409 yang menyebut status sekarang & siapa yang memutuskan terakhir. */
    public static function rejectionMessage(
        Reimbursement|Advance $record,
        string $action,
        bool $isAdvance = false
    ): string {
        $who = $record->decidedBy?->name;
        $when = $record->decided_at?->format('d M Y H:i');
        $last = $who ? " ($who" . ($when ? ', '.$when : '') . ')' : '';

        return sprintf(
            '%s sudah berstatus "%s"%s, sehingga aksi "%s" tidak berlaku.',
            $isAdvance ? 'Pengajuan' : 'Klaim',
            $record->status,
            $last,
            $action
        );
    }

    /** Label status yang enak dibaca untuk pesan. */
    public static function actionLabel(string $action): string
    {
        return match ($action) {
            'verify' => 'Verifikasi',
            'settlement' => 'Setujui Settlement',
            'disburse' => 'Disburse',
            'approve' => 'Setujui',
            'reject' => 'Tolak',
            default => $action,
        };
    }
}