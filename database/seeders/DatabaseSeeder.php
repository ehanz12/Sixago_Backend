<?php

namespace Database\Seeders;

use App\Models\ApprovalLevel;
use App\Models\ApprovalScheme;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed production-safe: roles, skema approval, dan 1 Super Admin.
     * Kredensial admin dibaca dari env (lihat .env.example).
     * Data demo/fiktif HANYA di DemoSeeder: php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $dept = Department::firstOrCreate(
            ['code' => 'CC-CORP-901'],
            ['name' => 'HR & Keuangan Korporat', 'budget_total' => 0, 'sla' => 'Board Direct Approval']
        );

        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@sixago.local')],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'nik' => env('ADMIN_NIK', '000001'),
                'password' => Hash::make("admin1234567"),
                'department_id' => $dept->id,
                'grade' => 'M2',
                'role' => 'Super Admin',
                'status' => 'Aktif',
                'must_change_password' => true,
            ]
        );
        $dept->update(['leader_id' => $admin->id]);

        $base = ['travel.view.own', 'travel.create', 'advance.view.own', 'advance.create', 'reimbursement.view.own', 'reimbursement.create', 'notification.view.own'];
        foreach (
            [
                ['Super Admin', 'Akses penuh root & konfigurasi', ['*']],
                [
                    'Finance Approver',
                    'Treasury, disbursement & verifikasi',
                    array_merge($base, ['travel.view.all', 'reimbursement.verify', 'reimbursement.settle', 'advance.decide', 'advance.disburse', 'project.sync'])
                ],
                [
                    'Division Head',
                    'Otoritas kuasa pengguna anggaran unit',
                    array_merge($base, ['approve.level2', 'department.budget', 'report.division', 'team.view'])
                ],
                [
                    'Line Manager',
                    'Approval tingkat 1',
                    array_merge($base, ['approve.level1', 'team.view'])
                ],
                ['Employee', 'Pembuatan pengajuan & laporan', $base],
            ] as [$name, $desc, $perms]
        ) {
            Role::updateOrCreate(['name' => $name], ['description' => $desc, 'permissions' => $perms]);
        }

        $scheme = ApprovalScheme::firstOrCreate(
            ['name' => 'Alur Standar Domestik'],
            ['description' => 'Perjalanan darat & udara dalam teritori RI', 'is_active' => true]
        );

        foreach (
            [
                [1, 'Level 1 - Line Manager', 'Line Manager', 'Semua pengajuan', 'Maks. 24 Jam kerja'],
                [2, 'Level 2 - Kepala Divisi (HOD)', 'Division Head', 'Estimasi > Rp 5.000.000', '24 Jam kerja'],
                [3, 'Level 3 - Finance & Treasury', 'Finance Approver', 'Ada kas bon / > Rp 15.000.000', '12 Jam kerja'],
                [4, 'Level 4 - CFO (Opsional)', 'CFO', 'Deviasi > 10% / > Rp 50.000.000', '48 Jam'],
                [5, 'Level 5 - Auto-Ticketing', 'System', 'Semua approval terpenuhi', 'Instant'],
            ] as [$step, $title, $role, $trigger, $sla]
        ) {
            ApprovalLevel::firstOrCreate(
                ['scheme_id' => $scheme->id, 'step' => $step],
                ['title' => $title, 'role' => $role, 'trigger' => $trigger, 'sla' => $sla, 'locked' => $step === 5]
            );
        }
    }
}
