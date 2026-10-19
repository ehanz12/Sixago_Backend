<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak policy yang benar-benar dipakai (grade pemohon), di samping
        // kolom policy_status (badge Sesuai/Deviasi). Null = tak ada policy cocok.
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->string('policy_code')->nullable()->after('policy_status');
        });
    }

    public function down(): void
    {
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->dropColumn('policy_code');
        });
    }
};
