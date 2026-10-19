<?php

namespace App\Support;

/**
 * Satu-satunya sumber kebenaran untuk string status di seluruh backend.
 *
 * Cermin frontend: app/constants/domain.ts (SixAgov2). Nilai string harus
 * sama persis — ubah dua-duanya. Dikunci test_status_constants_match_contract.
 */
final class TravelStatuses
{
    // Travel request
    public const TR_DRAFT = 'Draft';
    public const TR_SUBMITTED = 'Submitted';
    public const TR_IN_REVIEW = 'In Review';
    public const TR_APPROVED = 'Approved';
    public const TR_REJECTED = 'Rejected';
    public const TR_CANCELLED = 'Cancelled';
    public const TR_COMPLETED = 'Completed';

    // Advance (uang muka)
    public const ADV_PENDING = 'Pending Finance';
    public const ADV_READY = 'Approved-Ready to Pay';
    public const ADV_PAID = 'Paid';
    public const ADV_REJECTED = 'Rejected';

    // Reimbursement
    public const REIM_AUDIT = 'Menunggu Audit';
    public const REIM_VERIFY = 'Menunggu Verifikasi Finance';
    public const REIM_APPROVED = 'Disetujui';
    public const REIM_REJECTED = 'Ditolak';

    // Approval queue
    public const Q_PENDING = 'pending';
    public const Q_QUEUED = 'queued';
    public const Q_APPROVED = 'approved';
    public const Q_REJECTED = 'rejected';
    public const Q_CANCELLED = 'cancelled';

    // Badge kepatuhan policy
    public const POLICY_OK = 'Sesuai';
    public const POLICY_APPROVED = 'Policy Approved';
    public const POLICY_REJECTED = 'Ditolak';
}
