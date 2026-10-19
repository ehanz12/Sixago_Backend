<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Carbon;

/**
 * Pangkas audit log yang sudah melewati usia retensi.
 *
 * Audit log sengaja TIDAK punya delete per-item di API — menghapus satu
 * entri akan merusak jejaknya. Pembersihan karena itu harus dua hal: massal
 * dan berbasis usia, supaya yang tersisa bisa dibaca sebagai "apa yang terjadi
 * dalam N hari terakhir" tanpa bisa dimanipulasi selektif.
 *
 *   php artisan audit:prune --days=90
 *   php artisan audit:prune --days=90 --dry-run
 */
#[Signature('audit:prune {--days=90 : Umur retensi dalam hari} {--dry-run : Hanya hitung, jangan hapus}')]
#[Description('Hapus audit log yang lebih tua dari umur retensi (default 90 hari)')]
class AuditPrune extends \Illuminate\Console\Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('Umur retensi harus minimal 1 hari.');

            return self::INVALID;
        }

        $cutoff = Carbon::now()->subDays($days);
        $total = AuditLog::where('created_at', '<', $cutoff)->count();
        $byAction = AuditLog::where('created_at', '<', $cutoff)
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->orderByDesc('total')
            ->pluck('total', 'action');

        if ($total === 0) {
            $this->info("Tidak ada audit log yang lebih tua dari {$days} hari.");

            return self::SUCCESS;
        }

        $this->line("Batas: < {$cutoff->toDateTimeString()} ({$days} hari)");
        $this->line('Total: '.$total.' baris');
        foreach ($byAction as $action => $count) {
            $this->line("  - {$action}: {$count}");
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry-run — tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        $deleted = AuditLog::where('created_at', '<', $cutoff)->delete();
        $this->info("Terhapus: {$deleted} baris.");

        return self::SUCCESS;
    }
}