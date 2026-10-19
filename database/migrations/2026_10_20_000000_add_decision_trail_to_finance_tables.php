<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak siapa & kapan keputusan finance diambil pada uang muka & reimbursement.
     *
     * Sebelumnya hanya ada di audit_logs, jadi baris datanya sendiri tidak
     * pernah memberi tahu sudah diverifikasi siapa — Finance bisa menekan
     * "Verifikasi" berulang tanpa Sadanya. Kolom ini yang dipantik UI untuk
     * menampilkan "Sudah diverifikasi oleh …".
     */
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->foreignId('decided_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
        });

        Schema::table('advances', function (Blueprint $table) {
            $table->foreignId('decided_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn('decided_at');
        });

        Schema::table('advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn('decided_at');
        });
    }
};