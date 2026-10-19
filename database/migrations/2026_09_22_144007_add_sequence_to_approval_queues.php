<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('approval_queues', function (Blueprint $table) {
            // Urutan dalam rantai berjenjang (1..N) + approver struktural yang dituju.
            // Status: 'queued' (menunggu giliran), 'pending' (aktif), 'approved', 'rejected'.
            $table->integer('sequence')->default(1)->after('level');
            $table->foreignId('approver_id')->nullable()->after('sequence')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_queues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approver_id');
            $table->dropColumn('sequence');
        });
    }
};
