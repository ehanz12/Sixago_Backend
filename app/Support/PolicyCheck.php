<?php

namespace App\Support;

use App\Models\TravelPolicy;
use App\Models\User;

/**
 * Penilaian kepatuhan estimasi biaya terhadap matriks travel policy.
 *
 * Dua peran:
 * 1. Menentukan policy yang berlaku untuk grade user (resolve()) — sumber
 *    tunggal kebenaran untuk pembatas plafon di Travel Request & Uang Muka.
 * 2. Memberi label (badge) 'Sesuai' atau 'Deviasi N%' (check()).
 *
 * Pemanggil (controller) memakai resolve() untuk MENOLAK nominal yang
 * melebihi plafon — lihat TravelRequestController@store/update dan
 * AdvanceController@store/update.
 *
 * Rumus total plafon (didokumentasikan agar bisa diaudit):
 *   total  = transport_plafon + hotel_plafon × nights
 *          + per_diem × days + local_transport × days
 *   days   = max(1, end_date − start_date + 1)
 *   nights = max(1, days − 1)
 *
 * Nilai plafon di DB bertipe string bebas ("Maks Rp 4.500.000") sehingga
 * di-parse dengan parseRupiah() (ambil digit saja). Grade policy berupa
 * rentang string ("P3 - P5") yang dicocokkan dengan gradeMatches().
 */
class PolicyCheck
{
    /** "Maks Rp 4.500.000" → 4500000.0 ; string kosong → 0.0 */
    public static function parseRupiah(?string $value): float
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $value);

        return $digits === '' ? 0.0 : (float) $digits;
    }

    /** Apakah grade user ("P4") masuk cakupan grade policy ("P3 - P5" / "M2")? */
    public static function gradeMatches(string $policyGrade, string $userGrade): bool
    {
        $pg = strtoupper(trim($policyGrade));
        $ug = strtoupper(trim($userGrade));
        if ($pg === '' || $ug === '') {
            return false;
        }
        if ($pg === $ug) {
            return true;
        }
        // Rentang "P3 - P5": prefix huruf harus sama, batas numerik inklusif.
        if (preg_match('/^([A-Z]+)\s*(\d+)\s*-\s*([A-Z]+)\s*(\d+)$/', $pg, $m)
            && preg_match('/^([A-Z]+)\s*(\d+)$/', $ug, $u)) {
            if ($m[1] !== $m[3] || $m[1] !== $u[1]) {
                return false;
            }

            return ((int) $u[2] >= (int) $m[2]) && ((int) $u[2] <= (int) $m[4]);
        }

        // Fallback longgar untuk format tak dikenal.
        return str_contains($pg, $ug) || str_contains($ug, $pg);
    }

    public static function totalPlafon(TravelPolicy $policy, int $days): float
    {
        $days = max(1, $days);
        $nights = max(1, $days - 1);

        return self::parseRupiah($policy->transport_plafon)
            + self::parseRupiah($policy->hotel_plafon) * $nights
            + self::parseRupiah($policy->per_diem) * $days
            + self::parseRupiah($policy->local_transport) * $days;
    }

    public static function tripDays(string $startDate, string $endDate): int
    {
        try {
            $start = new \DateTimeImmutable($startDate);
            $end = new \DateTimeImmutable($endDate);

            return max(1, (int) $start->diff($end)->format('%a') + 1);
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * Policy yang berlaku untuk grade user + total plafonnya pada `days` hari.
     * Sumber tunggal kebenaran untuk pembatas plafon (Travel Request & Uang Muka).
     *
     * @return ?array{policy:TravelPolicy,total:float}
     *   null = tidak ada policy aktif yang cocok dengan grade user, atau semua
     *   total plafonnya 0. Bila >1 policy cocok, dipakai yang totalnya terkecil
     *   (paling konservatif).
     */
    public static function resolve(User $user, int $days): ?array
    {
        return TravelPolicy::where('enabled', true)->get()
            ->filter(fn ($p) => self::gradeMatches((string) $p->grade, (string) $user->grade))
            ->map(fn ($p) => ['policy' => $p, 'total' => self::totalPlafon($p, $days)])
            ->filter(fn ($c) => $c['total'] > 0)
            ->sortBy('total')
            ->values()
            ->first();
    }

    /**
     * Apakah estimasi biaya masih dalam total plafon policy grade user?
     * true juga saat tidak ada policy (tanpa pembatas).
     */
    public static function withinLimit(User $user, float $cost, int $days): bool
    {
        $limit = self::resolve($user, $days);

        return $limit === null || $cost <= $limit['total'];
    }

    /**
     * Nilai kepatuhan untuk estimasi biaya.
     *
     * @return ?array{policy_code:string,total:float,status:string,deviation_pct:float}
     *   status: 'Sesuai' | 'Deviasi N%'. null = tidak ada policy aktif yang
     *   cocok dengan grade user (perilaku lama: policy_status tetap null).
     *   Bila >1 policy cocok, dipakai yang totalnya terkecil (paling konservatif).
     */
    public static function check(User $user, float $cost, int $days): ?array
    {
        $best = self::resolve($user, $days);
        if ($best === null) {
            return null;
        }

        $total = $best['total'];
        if ($cost <= $total) {
            return ['policy_code' => $best['policy']->code, 'total' => $total, 'status' => TravelStatuses::POLICY_OK, 'deviation_pct' => 0.0];
        }

        $pct = round((($cost - $total) / $total) * 100, 1);

        return ['policy_code' => $best['policy']->code, 'total' => $total, 'status' => "Deviasi {$pct}%", 'deviation_pct' => $pct];
    }
}
