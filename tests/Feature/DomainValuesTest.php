<?php

namespace Tests\Feature;

use App\Support\TravelStatuses;
use App\Support\UserRoles;
use Tests\TestCase;

/**
 * Kunci kontrak: nilai konstanta backend harus sama persis dengan string yang
 * dipakai respons API — dan dengan cermin frontend app/constants/domain.ts.
 * Ubah nilai di sini + di frontend bersamaan.
 */
class DomainValuesTest extends TestCase
{
    public function test_status_constants_match_contract(): void
    {
        $this->assertSame('Submitted', TravelStatuses::TR_SUBMITTED);
        $this->assertSame('In Review', TravelStatuses::TR_IN_REVIEW);
        $this->assertSame('Approved', TravelStatuses::TR_APPROVED);
        $this->assertSame('Rejected', TravelStatuses::TR_REJECTED);
        $this->assertSame('Cancelled', TravelStatuses::TR_CANCELLED);
        $this->assertSame('Draft', TravelStatuses::TR_DRAFT);
        $this->assertSame('Completed', TravelStatuses::TR_COMPLETED);

        $this->assertSame('Pending Finance', TravelStatuses::ADV_PENDING);
        $this->assertSame('Approved-Ready to Pay', TravelStatuses::ADV_READY);
        $this->assertSame('Paid', TravelStatuses::ADV_PAID);

        $this->assertSame('Menunggu Audit', TravelStatuses::REIM_AUDIT);
        $this->assertSame('Menunggu Verifikasi Finance', TravelStatuses::REIM_VERIFY);
        $this->assertSame('Disetujui', TravelStatuses::REIM_APPROVED);
        $this->assertSame('Ditolak', TravelStatuses::REIM_REJECTED);

        $this->assertSame('pending', TravelStatuses::Q_PENDING);
        $this->assertSame('queued', TravelStatuses::Q_QUEUED);
        $this->assertSame('approved', TravelStatuses::Q_APPROVED);
        $this->assertSame('rejected', TravelStatuses::Q_REJECTED);
        $this->assertSame('cancelled', TravelStatuses::Q_CANCELLED);

        $this->assertSame('Sesuai', TravelStatuses::POLICY_OK);
    }

    public function test_role_constants_match_contract(): void
    {
        $this->assertSame(
            ['Super Admin', 'Finance Approver', 'Division Head', 'Line Manager', 'Employee'],
            [UserRoles::SUPER_ADMIN, UserRoles::FINANCE, UserRoles::DIVISION_HEAD, UserRoles::LINE_MANAGER, UserRoles::EMPLOYEE]
        );
        $this->assertSame(UserRoles::all(), [
            UserRoles::SUPER_ADMIN, UserRoles::FINANCE, UserRoles::DIVISION_HEAD,
            UserRoles::LINE_MANAGER, UserRoles::EMPLOYEE,
        ]);
    }
}
