<?php

use App\Models\TravelRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Isi `duration` pada baris travel_requests lama yang masih kosong.
     *
     * Durasi kini diturunkan otomatis dari start_date/end_date (lihat
     * TravelRequest::durationLabel), jadi baris lama yang belum punya nilai
     * perlu diisi agar tampilan tabel/detail konsisten dengan data baru.
     *
     * Sengaja memakai PHP (bukan raw SQL) agar identik untuk MySQL & SQLite,
     * dan memakai query builder per baris agar `updated_at` historis tidak
     * ikut tersentuh.
     */
    public function up(): void
    {
        if (! Schema::hasTable('travel_requests') || ! Schema::hasColumn('travel_requests', 'duration')) {
            return;
        }

        TravelRequest::query()
            ->where(fn ($q) => $q->whereNull('duration')->orWhere('duration', ''))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereColumn('end_date', '>=', 'start_date')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $tr) {
                    DB::table('travel_requests')
                        ->where('id', $tr->id)
                        ->update([
                            'duration' => TravelRequest::durationLabel((string) $tr->start_date, (string) $tr->end_date),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Tidak di-rollback: nilai duration adalah turunan, tidak menyimpan
        // informasi lama yang perlu dipulihkan.
    }
};