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
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->integer('members')->default(0);
            $table->decimal('budget_used', 15, 2)->default(0);
            $table->decimal('budget_total', 15, 2)->default(0);
            $table->string('sla');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('nik')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('department_id')->nullable()->constrained();
            $table->string('grade');
            $table->string('role');
            $table->foreignId('manager_id')->nullable()->constrained('users');
            $table->string('avatar')->nullable();
            $table->string('status')->default('Aktif');
            $table->string('whatsapp')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('leader_id')->nullable()->constrained('users');
        });

        Schema::create('travel_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('grade');
            $table->string('transport_plafon');
            $table->string('hotel_plafon');
            $table->string('per_diem');
            $table->string('local_transport');
            $table->string('sla');
            $table->boolean('enabled')->default(true);
            $table->string('region');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('wbs');
            $table->string('name');
            $table->foreignId('pm_id')->constrained('users');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('budget_used', 15, 2)->default(0);
            $table->decimal('budget_total', 15, 2)->default(0);
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        Schema::create('travel_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->string('route');
            $table->string('transport');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('cost', 15, 2);
            $table->string('status')->default('Draft');
            $table->text('purpose')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained();
            $table->string('type');
            $table->string('level');
            $table->string('status');
            $table->boolean('urgent')->default(false);
            $table->timestamps();
        });

        Schema::create('reimbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained();
            $table->decimal('actual_expense', 15, 2);
            $table->string('ocr_status');
            $table->integer('receipt_count')->default(0);
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained();
            $table->decimal('amount', 15, 2);
            $table->string('method');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users');
            $table->foreignId('delegatee_id')->constrained('users');
            $table->string('scope');
            $table->string('status');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
            $table->text('message');
            $table->string('type');
            $table->boolean('read')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('delegations');
        Schema::dropIfExists('advances');
        Schema::dropIfExists('reimbursements');
        Schema::dropIfExists('approval_queues');
        Schema::dropIfExists('travel_requests');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('travel_policies');
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['leader_id']);
        });
        Schema::dropIfExists('users');
        Schema::dropIfExists('departments');
    }
};
