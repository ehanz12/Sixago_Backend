<?php

namespace Database\Seeders;

use App\Models\Advance;
use App\Models\ApprovalQueue;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Reimbursement;
use App\Models\TravelPolicy;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Data DEMO/fiktif untuk pengembangan & presentasi.
 * JANGAN dijalankan di production:
 *   php artisan db:seed --class=DemoSeeder   (local saja)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('DemoSeeder diblokir di environment production.');
            return;
        }

        $corp = Department::where('code', 'CC-CORP-901')->firstOrFail();
        $admin = User::where('role', 'Super Admin')->firstOrFail();

        $it = Department::firstOrCreate(
            ['code' => 'CC-TECH-502'],
            ['name' => 'IT & Rekayasa Sistem', 'budget_total' => 350000000, 'budget_used' => 224000000, 'sla' => 'Tier 1 Approval Flow']
        );
        $sales = Department::firstOrCreate(
            ['code' => 'CC-SALE-101'],
            ['name' => 'Komersial & Enterprise Sales', 'budget_total' => 450000000, 'budget_used' => 351000000, 'sla' => 'Tier 1 Fast-Track Approval']
        );

        foreach (
            [
                ['michael@nusantara.co.id', 'Ir. Michael Wijaya', '885012', 'Division Head', 'M3', $it->id, null],
                ['hendra@nusantara.co.id', 'Hendra Gunawan', '782011', 'Line Manager', 'M3', $it->id, null],
                ['ratna@nusantara.co.id', 'Ratna Paramita', '990215', 'Finance Approver', 'M2', $corp->id, null],
            ] as [$email, $name, $nik, $role, $grade, $deptId, $managerId]
        ) {
            User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'nik' => $nik,
                    'password' => Hash::make('password123'),
                    'department_id' => $deptId,
                    'grade' => $grade,
                    'role' => $role,
                    'status' => 'Aktif',
                    'manager_id' => $managerId,
                ]
            );
        }
        $hendra = User::where('email', 'hendra@nusantara.co.id')->first();
        $budi = User::firstOrCreate(
            ['email' => 'budi@nusantara.co.id'],
            [
                'name' => 'Budi Santoso',
                'nik' => '2405-0821',
                'password' => Hash::make('password123'),
                'department_id' => $it->id,
                'grade' => 'P4',
                'role' => 'Employee',
                'status' => 'Aktif',
                'manager_id' => $hendra?->id ?? $admin->id
            ]
        );
        $siti = User::firstOrCreate(
            ['email' => 'siti@nusantara.co.id'],
            [
                'name' => 'Siti Nurhaliza',
                'nik' => '2405-0822',
                'password' => Hash::make('password123'),
                'department_id' => $sales->id,
                'grade' => 'P3',
                'role' => 'Employee',
                'status' => 'Aktif',
                'manager_id' => $admin->id
            ]
        );
        $it->update(['leader_id' => User::where('email', 'michael@nusantara.co.id')->value('id')]);

        // Kepala Divisi = atasan langsung para Line Manager (Positions::ROLE_MATRIX).
        // Tanpa ini Level 1 dilewati dan rantai mulai dari Level 2.
        User::where('role', 'Line Manager')
            ->whereNull('manager_id')
            ->update(['manager_id' => User::where('email', 'michael@nusantara.co.id')->value('id')]);

        TravelPolicy::firstOrCreate(
            ['code' => 'POL-IDN-MGR-03'],
            [
                'grade' => 'P3 - P5',
                'transport_plafon' => 'Maks Rp 4.500.000',
                'hotel_plafon' => 'Maks Rp 1.100.000 / malam',
                'per_diem' => 'Rp 450.000',
                'local_transport' => 'Maks Rp 200.000 / hari',
                'sla' => '3 Hari Kerja',
                'region' => 'Domestik',
                'enabled' => true
            ]
        );

        // Plafon grade M (M1-M3). Tanpa ini seluruh.management travel
        // TIDAK punya plafon sama sekali karena policy di atas hanya P3-P5.
        // 5 hari = 7.500.000 + (1.800.000 x 4) + (700.000 x 5) + (350.000 x 5)
        //        = 19.950.000
        TravelPolicy::firstOrCreate(
            ['code' => 'POL-IDN-EXEC-01'],
            [
                'grade' => 'M1 - M3',
                'transport_plafon' => 'Maks Rp 7.500.000',
                'hotel_plafon' => 'Maks Rp 1.800.000 / malam',
                'per_diem' => 'Rp 700.000',
                'local_transport' => 'Maks Rp 350.000 / hari',
                'sla' => '2 Hari Kerja',
                'region' => 'Domestik',
                'enabled' => true
            ]
        );

        $project = Project::firstOrCreate(
            ['code' => 'PRJ-2025-01'],
            [
                'wbs' => '884.09.21',
                'name' => 'Q2 Data Center Resiliency Rungkut',
                'pm_id' => $budi->id,
                'start_date' => '2025-01-01',
                'end_date' => '2025-06-30',
                'budget_total' => 150000000,
                'budget_used' => 98450000,
                'status' => 'Active',
                'trips' => 14
            ]
        );

        $tr = TravelRequest::firstOrCreate(
            ['request_no' => 'TR-2025-104'],
            [
                'user_id' => $budi->id,
                'project_id' => $project->id,
                'route' => 'CGK → SUB',
                'transport' => 'Garuda Indonesia',
                'start_date' => '2025-05-18',
                'end_date' => '2025-05-22',
                'duration' => '5 Hari (4 Malam)',
                'cost' => 9450000,
                'status' => 'Submitted',
                // Policy otomatis dari grade pemohon (P4 ⊂ "P3 - P5"); 5 hari.
                'policy_status' => 'Sesuai',
                'policy_code' => 'POL-IDN-MGR-03',
                'purpose' => 'Maintenance Data Center Rungkut'
            ]
        );
        // Rantai dibentuk lewat builder (cost 9,45jt → Level 1 + Level 2).
        if (! ApprovalQueue::where('travel_request_id', $tr->id)->exists()) {
            \App\Support\ApprovalChain::build($tr->fresh(), request());

            // Paksa ke kondisi mid-chain: Level 1 sudah disetujui Line Manager,
            // Level 2 menunggu Kepala Divisi. Ini skenario yang ditampilkan
            // demo — approver Level 1 harus melihat "Sudah Anda Setujui",
            // bukan tombol Setujui lagi untuk level berikutnya.
            $michael = User::where('email', 'michael@nusantara.co.id')->first();
            $chain = ApprovalQueue::where('travel_request_id', $tr->id)
                ->orderBy('sequence')
                ->get();
            $l1 = $chain->firstWhere('sequence', 1);
            $l2 = $chain->firstWhere('sequence', 2);

            if ($l1 && $hendra) {
                $l1->update([
                    'status' => 'approved',
                    'decided_by' => $hendra->id,
                    'decided_at' => now()->subHours(20),
                    'note' => 'Rencana perjalanan dan esfonibilitas sesuai. Lanjutkan ke Kepala Divisi.',
                ]);
            }
            if ($l2) {
                $l2->update([
                    'status' => 'pending',
                    'urgent' => true,
                    'sla_due_at' => now()->addHours(4),
                    'approver_id' => $l2->approver_id ?: $michael?->id,
                ]);
            }
            $tr->update(['status' => 'In Review', 'policy_status' => 'Sesuai']);
        }

        Advance::firstOrCreate(
            ['request_no' => 'ADV-2025-042'],
            [
                'travel_request_id' => $tr->id,
                'amount' => 5000000,
                'plafon' => 6500000,
                'account' => 'BCA • 7100-••••-8720',
                'account_name' => 'a.n Bambang Wicaksono',
                'method' => 'Bank Transfer',
                'status' => 'Approved-Ready to Pay',
                'purpose' => 'Maintenance Data Center',
                'destination' => 'Surabaya, Jatim'
            ]
        );
        Reimbursement::firstOrCreate(
            ['request_no' => 'REIM-2025-081'],
            [
                'travel_request_id' => $tr->id,
                'actual_expense' => 9350000,
                'advance' => 5000000,
                'reconciliation' => '+ Rp 4350000 Reimbursable',
                'ocr_status' => 'Valid',
                'receipt_count' => 12,
                'status' => 'Menunggu Audit'
            ]
        );

        // Delegasi demo: Kepala Divisi (Michael) melimpahkan wewenang Level 2
        // ke Line Manager (Hendra) karena cuti. Keduanya role approver —
        // delegasi ke staf biasa ditolak (Positions::isApproverCapable).
        // Periode dibuat BERJALAN supaya alur Plt bisa langsung dicoba:
        // antrean Level 2 milik Michael ikut tampil di antrean Hendra.
        $michael = User::where('email', 'michael@nusantara.co.id')->first();
        if ($michael && $hendra) {
            Delegation::updateOrCreate(
                [
                    'delegator_id' => $michael->id,
                    'delegatee_id' => $hendra->id,
                    'scope' => 'Penuh (Full Approval)',
                ],
                [
                    'reason' => 'Cuti Tahunan — Hendra memegang Level 2',
                    'max_amount' => 50000000,
                    'status' => 'Aktif',
                    'start_date' => now()->subDays(3)->toDateString(),
                    'end_date' => now()->addDays(11)->toDateString(),
                ]
            );
        }

        Notification::firstOrCreate(
            ['user_id' => $admin->id, 'reference_id' => 'TR-2025-104'],
            [
                'title' => 'Permohonan TR-2025-104 membutuhkan otorisasi Level 1',
                'message' => 'Budi Santoso mengajukan perjalanan dinas ke Surabaya. Sisa SLA 24 jam.',
                'type' => 'Urgent',
                'category' => 'urgent',
                'read' => false
            ]
        );
    }
}
