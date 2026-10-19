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
        Schema::table('users', function (Blueprint $table) {
            $table->string('nik')->unique()->after('id');
            $table->string('grade')->after('manager_id');
            $table->string('status')->default('Aktif')->after('grade');
            $table->string('avatar')->nullable()->after('status');
            $table->string('whatsapp')->nullable()->after('avatar');
            $table->timestamp('last_active')->nullable()->after('whatsapp');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->string('code')->unique()->after('id');
            $table->integer('members')->default(0)->after('name');
            $table->decimal('budget_used', 15, 2)->default(0)->after('members');
            $table->decimal('budget_total', 15, 2)->default(0)->after('budget_used');
            $table->string('sla')->after('budget_total');
            $table->foreignId('leader_id')->nullable()->constrained('users')->after('sla');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('code')->unique()->after('id');
            $table->string('wbs')->after('code');
            $table->decimal('budget_used', 15, 2)->default(0)->after('end_date');
            $table->decimal('budget_total', 15, 2)->default(0)->after('budget_used');
            $table->integer('trips')->default(0)->after('budget_total');
        });

        Schema::table('travel_requests', function (Blueprint $table) {
            $table->string('request_no')->unique()->after('id');
            $table->string('route')->after('project_id');
            $table->string('transport')->after('route');
            $table->string('duration')->after('end_date');
            $table->string('policy_status')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['nik', 'grade', 'status', 'avatar', 'whatsapp', 'last_active']);
        });
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['leader_id']);
            $table->dropColumn(['code', 'members', 'budget_used', 'budget_total', 'sla', 'leader_id']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['code', 'wbs', 'budget_used', 'budget_total', 'trips']);
        });
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->dropColumn(['request_no', 'route', 'transport', 'duration', 'policy_status']);
        });
    }
};
