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
        Schema::table('advances', function (Blueprint $table) {
            $table->string('transfer_ref')->nullable()->after('status');
            $table->string('proof_path')->nullable()->after('transfer_ref');
            $table->timestamp('disbursed_at')->nullable()->after('proof_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advances', function (Blueprint $table) {
            $table->dropColumn(['transfer_ref', 'proof_path', 'disbursed_at']);
        });
    }
};
