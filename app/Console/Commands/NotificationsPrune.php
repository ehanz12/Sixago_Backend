<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Carbon;

/**
 * Pangkas notifikasi yang sudah dibaca dan sudah tua.
 *
 * Hanya notifikasi yang SUDAH DIBACA yang dihapus. Notifikasi belum dibaca tidak
 * pernah dihapus otomatis supaya badge user tidak tiba-tiba kosong tanpa
 * jejak — kalau memang tidak relevan, user sendiri yang menghapusnya lewat
 * tombol di halaman Notifikasi.
 */
#[Signature('notifications:prune {--days=90 : Umur retensi dalam hari} {--dry-run : Hanya hitung, jangan hapus}')]
#[Description('Hapus notifikasi yang sudah dibaca dan lebih tua dari umur retensi (default 90 hari)')]
class NotificationsPrune extends \Illuminate\Console\Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('Umur retensi harus minimal 1 hari.');

            return self::INVALID;
        }

        $cutoff = Carbon::now()->subDays($days);

        $stale = Notification::where('read', true)->where('created_at', '<', $cutoff);
        $total = (clone $stale)->count();
        $kept = Notification::where('read', false)->count();

        if ($total === 0) {
            $this->info("Tidak ada notifikasi terbaca yang lebih tua dari {$days} hari.");

            return self::SUCCESS;
        }

        $this->line("Batas: < {$cutoff->toDateTimeString()} ({$days} hari)");
        $this->line("Terbaca & terlalu tua: {$total} baris");
        $this->line('Belum dibaca (diselamatkan): '.$kept.' baris');

        if ($this->option('dry-run')) {
            $this->comment('Dry-run — tidak ada yang dihapus.');

            return self::SUCCESS;
        }

        $deleted = Notification::where('read', true)->where('created_at', '<', $cutoff)->delete();
        $this->info("Terhapus: {$deleted} baris.");

        return self::SUCCESS;
    }
}