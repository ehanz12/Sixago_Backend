<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolom tambahan sinkron kebutuhan frontend (filter, tabel, KPI)
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('trips')->default(0)->after('budget_total');
        });

        Schema::table('travel_requests', function (Blueprint $table) {
            $table->string('duration')->nullable()->after('end_date');
            $table->string('policy_status')->nullable()->after('status');
        });

        Schema::table('approval_queues', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->default(0)->after('type');
            $table->text('justification')->nullable()->after('amount');
        });

        Schema::table('reimbursements', function (Blueprint $table) {
            $table->string('request_no')->nullable()->after('id');
            $table->decimal('advance', 15, 2)->default(0)->after('actual_expense');
            $table->string('reconciliation')->nullable()->after('advance');
        });

        Schema::table('advances', function (Blueprint $table) {
            $table->string('request_no')->nullable()->after('id');
            $table->decimal('plafon', 15, 2)->default(0)->after('amount');
            $table->string('account')->nullable()->after('plafon');
            $table->string('account_name')->nullable()->after('account');
            $table->string('purpose')->nullable()->after('account_name');
            $table->string('destination')->nullable()->after('purpose');
        });

        Schema::table('delegations', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('scope');
            $table->decimal('max_amount', 15, 2)->default(0)->after('reason');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->string('category')->default('system')->after('type');
            $table->string('reference_id')->nullable()->after('category');
        });

        // Tabel roles (matriks hak akses — halaman departments tab roles)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        // Tabel skema approval (halaman approval-levels: scheme switcher)
        Schema::create('approval_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // Tabel level approval (halaman approval-levels: hierarchy builder)
        Schema::create('approval_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheme_id')->constrained('approval_schemes')->cascadeOnDelete();
            $table->integer('step');
            $table->string('title');
            $table->string('role');
            $table->string('trigger')->nullable();
            $table->string('sla')->nullable();
            $table->boolean('locked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_levels');
        Schema::dropIfExists('approval_schemes');
        Schema::dropIfExists('roles');

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['category', 'reference_id']);
        });
        Schema::table('delegations', function (Blueprint $table) {
            $table->dropColumn(['reason', 'max_amount']);
        });
        Schema::table('advances', function (Blueprint $table) {
            $table->dropColumn(['request_no', 'plafon', 'account', 'account_name', 'purpose', 'destination']);
        });
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropColumn(['request_no', 'advance', 'reconciliation']);
        });
        Schema::table('approval_queues', function (Blueprint $table) {
            $table->dropColumn(['amount', 'justification']);
        });
        Schema::table('travel_requests', function (Blueprint $table) {
            $table->dropColumn(['duration', 'policy_status']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['trips']);
        });
    }
};
