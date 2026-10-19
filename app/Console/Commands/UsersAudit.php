<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\User;
use App\Support\Positions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Audit struktur posisi — READ-ONLY.
 *
 * Menecor data yang sudah ada terhadap matriks Positions. Tidak mengubah
 * apa pun; perbaikannya tetap keputusan manusia. Jalankan sebelum menyalakan
 * validasi baru agar knowing shocking-nya ketahuan lebih dulu:
 *
 *   php artisan users:audit
 */
#[Signature('users:audit')]
#[Description('Audit role/grade/atasan/departemen terhadap matriks posisi (read-only)')]
class UsersAudit extends Command
{
    public function handle(): int
    {
        $findings = [];
        $coverage = Positions::gradeCoverage();

        foreach (User::with(['manager', 'department'])->orderBy('id')->get() as $user) {
            $label = $user->name.' (id '.$user->id.')';

            // 1. Role di luar daftar kanonik.
            if (! Positions::isKnownRole($user->role)) {
                $findings[] = [$label, 'ROLE_TAK_DIKENAL', 'Role "'.$user->role.'" bukan role kanonik.'];
            }

            // 2. Grade di luar daftar kanonik.
            if (! Positions::isKnownGrade($user->grade)) {
                $findings[] = [$label, 'GRADE_TAK_DIKENAL', 'Grade "'.$user->grade.'" bukan grade kanonik.'];
            }

            // 3. Grade di luar rentang role.
            if (Positions::isKnownRole($user->role) && Positions::isKnownGrade($user->grade)
                && ! Positions::roleAllowsGrade($user->role, $user->grade)) {
                $findings[] = [$label, 'GRADE_DI_RENTANG', 'Grade '.$user->grade.' tidak sesuai role '.$user->role
                    .' (sah: '.implode(', ', Positions::gradesForRole($user->role)).').'];
            }

            // 4. Grade tanpa travel policy → plafon tak terbatas.
            if (Positions::isKnownGrade($user->grade) && in_array($user->grade, $coverage['uncovered'], true)) {
                $findings[] = [$label, 'TANPA_PLAFON', 'Grade '.$user->grade.' tidak tercakup travel policy aktif → plafon tak terbatas.'];
            }

            // 5. Atasan langsung bermasalah.
            if ($user->manager_id === null) {
                if (Positions::requiresManager($user->role)) {
                    $findings[] = [$label, 'TANPA_ATASAN', 'Role '.$user->role.' wajib punya atasan langsung.'];
                }
            } else {
                if ($user->manager_id === $user->id) {
                    $findings[] = [$label, 'ATASAN_DIRI', 'Atasan langsung menunjuk dirinya sendiri.'];
                } elseif ($user->manager !== null) {
                    if ($user->manager->status !== 'Aktif') {
                        $findings[] = [$label, 'ATASAN_NONAKTIF', 'Atasan langsung ('.$user->manager->name.') berstatus '.$user->manager->status.'.'];
                    }
                    if (! Positions::isApproverCapable($user->manager->role)) {
                        $findings[] = [$label, 'ATASAN_BUKAN_APPROVER', 'Role atasan ('.$user->manager->role.') tidak berwenang approve.'];
                    }
                    $chain = Positions::inspectManagerChain($user->manager, (int) $user->id);
                    if ($chain['cycle']) {
                        $findings[] = [$label, 'SIKLUS_ATASAN', $chain['reason']];
                    }
                }
            }
        }

        // 6. Departemen tanpa Kepala Divisi → Level 2 dilewati diam-diam.
        foreach (Department::with('leader')->orderBy('id')->get() as $dept) {
            if ($dept->leader_id === null) {
                $findings[] = ['Departemen "'.$dept->name.'" (id '.$dept->id.')', 'DEPT_TANPA_LEADER', 'Level 2 akan dilewati otomatis untuk pengajuan anggota departemen ini.'];
            } elseif ($dept->leader !== null
                && ! $dept->leader->isSuperAdmin()
                && ! $dept->leader->hasRole(\App\Support\UserRoles::DIVISION_HEAD)) {
                $findings[] = ['Departemen "'.$dept->name.'" (id '.$dept->id.')', 'LEADER_BUKAN_HOD', 'Leader ('.$dept->leader->name.') bukan Division Head.'];
            }
        }

        $this->newLine();
        $this->info('Cakupan travel policy');
        $this->line('  Tercakup : '.($coverage['covered'] ? implode(', ', $coverage['covered']) : '(tidak ada)'));
        $this->line('  Tanpa plafon: '.($coverage['uncovered'] ? implode(', ', $coverage['uncovered']) : '(tidak ada)'));
        $this->newLine();

        if ($findings === []) {
            $this->info('Tidak ada pelanggaran. Seluruh data sesuai matriks posisi.');

            return self::SUCCESS;
        }

        $this->warn(count($findings).' temuan:');
        $this->table(
            ['Subjek', 'Kode', 'Keterangan'],
            $findings
        );
        $this->newLine();
        $this->comment('Perintah ini hanya melaporkan — tidak mengubah data.');

        return self::SUCCESS;
    }
}