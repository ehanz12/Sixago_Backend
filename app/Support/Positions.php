<?php

namespace App\Support;

use App\Models\TravelPolicy;
use App\Models\User;

/**
 * Matriks posisi: mengikat GRADE jabatan ↔ ROLE SixAgo ↔ struktur atasan.
 *
 * Tanpa file ini ketiga hal itu berdiri sendiri-sendiri dan tidak ada yang
 * saling menegakkan — grade ngawol tetap diterima, role ngawol tetap diterima,
 * dan atasan bisa menunjuk siapa saja.
 *
 * Cermin frontend: POSITION_MATRIX di app/constants/domain.ts (SixAgov2).
 * Nilai string role/grade WAJIB sama persis dengan UserRoles & kolom users.
 *
 * GRADE menentukan plafon perjalanan (PolicyCheck::gradeMatches), BUKAN
 * wewenang. ROLE menentukan wewenang approval (User::canDecideOwn).
 * Keduanya independen — hanya boleh dipasangkan lewat matriks ini.
 */
final class Positions
{
    /**
     * Grade kanonik → peringkat.수록 = makin tinggi.
     * P = profesional (P1..P5), M = manajemen (M1..M3).
     *
     * @var array<string, int>
     */
    public const GRADES = [
        'P1' => 1,
        'P2' => 2,
        'P3' => 3,
        'P4' => 4,
        'P5' => 5,
        'M1' => 6,
        'M2' => 7,
        'M3' => 8,
    ];

    /**
     * Rentang grade yang sah per role (berdasarkan peringkat).
     *
     * `approver`    → boleh menjadi atasan langsung / approver struktural.
     * `needsManager`→ atasan langsung wajib diisi.
     *
     * @var array<string, array{min: string, max: string, approver: bool, needsManager: bool}>
     */
    public const ROLE_MATRIX = [
        UserRoles::EMPLOYEE => ['min' => 'P1', 'max' => 'P5', 'approver' => false, 'needsManager' => true],
        UserRoles::LINE_MANAGER => ['min' => 'P4', 'max' => 'M3', 'approver' => true, 'needsManager' => false],
        UserRoles::DIVISION_HEAD => ['min' => 'M1', 'max' => 'M3', 'approver' => true, 'needsManager' => false],
        UserRoles::FINANCE => ['min' => 'P1', 'max' => 'M3', 'approver' => true, 'needsManager' => false],
        UserRoles::SUPER_ADMIN => ['min' => 'M1', 'max' => 'M3', 'approver' => true, 'needsManager' => false],
    ];

    /** @return string[] */
    public static function grades(): array
    {
        return array_keys(self::GRADES);
    }

    /** Peringkat grade, atau null bila grade di luar daftar kanonik. */
    public static function gradeRank(?string $grade): ?int
    {
        if ($grade === null) {
            return null;
        }

        return self::GRADES[strtoupper(trim($grade))] ?? null;
    }

    public static function isKnownGrade(?string $grade): bool
    {
        return self::gradeRank($grade) !== null;
    }

    public static function isKnownRole(?string $role): bool
    {
        return $role !== null && array_key_exists($role, self::ROLE_MATRIX);
    }

    /** Aturan matriks untuk role; null bila role di luar daftar kanonik. */
    public static function matrixFor(?string $role): ?array
    {
        return $role === null ? null : (self::ROLE_MATRIX[$role] ?? null);
    }

    /**
     * Apakah grade ini sah untuk role tersebut (di dalam rentang)?
     * Role/grade di luar daftar → false.
     */
    public static function roleAllowsGrade(?string $role, ?string $grade): bool
    {
        $rule = self::matrixFor($role);
        $rank = self::gradeRank($grade);
        if ($rule === null || $rank === null) {
            return false;
        }

        return $rank >= self::GRADES[$rule['min']] && $rank <= self::GRADES[$rule['max']];
    }

    /** @return string[] grade yang sah untuk role, urut dari yang terendah. */
    public static function gradesForRole(?string $role): array
    {
        $rule = self::matrixFor($role);
        if ($rule === null) {
            return [];
        }
        $min = self::GRADES[$rule['min']];
        $max = self::GRADES[$rule['max']];

        $out = [];
        foreach (self::GRADES as $grade => $rank) {
            if ($rank >= $min && $rank <= $max) {
                $out[] = $grade;
            }
        }

        return $out;
    }

    public static function requiresManager(?string $role): bool
    {
        return (bool) (self::matrixFor($role)['needsManager'] ?? false);
    }

    public static function isApproverCapable(?string $role): bool
    {
        return (bool) (self::matrixFor($role)['approver'] ?? false);
    }

    /** Ringkasan grade → apakah ada travel policy aktif yang memuatnya. */
    public static function gradeCoverage(): array
    {
        $policies = TravelPolicy::where('enabled', true)->get();
        $covered = [];
        foreach (self::GRADES as $grade => $_) {
            foreach ($policies as $p) {
                if (PolicyCheck::gradeMatches((string) $p->grade, $grade)) {
                    $covered[] = $grade;
                    break;
                }
            }
        }

        return [
            'covered' => $covered,
            'uncovered' => array_values(array_diff(self::grades(), $covered)),
        ];
    }

    /**
     * Naik rantai atasan dari $from untuk mendeteksi siklus & apakah kembali ke $userId.
     *
     * Mengembalikan ['cycle' => bool, 'reason' => ?string]. Batas hop mencegah
     * loop tak terbatas bila data sudah rusak.
     *
     * @return array{cycle: bool, reason: ?string}
     */
    public static function inspectManagerChain(?User $from, int $userId, int $maxHops = 20): array
    {
        $seen = [];
        $cursor = $from;
        $hops = 0;

        while ($cursor !== null) {
            if ((int) $cursor->id === $userId) {
                return ['cycle' => true, 'reason' => 'Atasan langsung membentuk lingkaran (kembali ke user itu sendiri).'];
            }
            if (isset($seen[(int) $cursor->id])) {
                return ['cycle' => true, 'reason' => 'Rantai atasan sudah melingkar lebih dulu (data rusak).'];
            }
            $seen[(int) $cursor->id] = true;
            if (++$hops >= $maxHops) {
                return ['cycle' => true, 'reason' => 'Rantai atasan terlalu dalam ('.$maxHops.' tingkat) — indikasi lingkaran.'];
            }
            $cursor = $cursor->manager;
        }

        return ['cycle' => false, 'reason' => null];
    }
}