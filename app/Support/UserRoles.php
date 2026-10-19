<?php

namespace App\Support;

/**
 * Satu-satunya sumber kebenaran untuk nama role kanonik.
 *
 * Cermin frontend: ROLES di app/constants/domain.ts (SixAgov2). Nilai string
 * harus sama persis — ubah dua-duanya. Dikunci test_status_constants_match_contract.
 */
final class UserRoles
{
    public const SUPER_ADMIN = 'Super Admin';
    public const FINANCE = 'Finance Approver';
    public const DIVISION_HEAD = 'Division Head';
    public const LINE_MANAGER = 'Line Manager';
    public const EMPLOYEE = 'Employee';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::SUPER_ADMIN,
            self::FINANCE,
            self::DIVISION_HEAD,
            self::LINE_MANAGER,
            self::EMPLOYEE,
        ];
    }
}
