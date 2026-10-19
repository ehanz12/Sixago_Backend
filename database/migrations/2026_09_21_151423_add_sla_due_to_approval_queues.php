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
            $table->timestamp('sla_due_at')->nullable()->after('status');
            $table->boolean('escalated')->default(false)->after('sla_due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_queues', function (Blueprint $table) {
            $table->dropColumn(['sla_due_at', 'escalated']);
        });
    }
};
