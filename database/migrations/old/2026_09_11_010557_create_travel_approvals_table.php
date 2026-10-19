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
        Schema::create('travel_approvals', function (Blueprint $table) {
        $table->id();
        $table->foreignId('travel_request_id')->constrained('travel_requests')->onDelete('cascade');
        $table->foreignId('approver_id')->constrained('users')->onDelete('cascade');
        $table->foreignId('approval_level_id')->constrained('approval_levels')->onDelete('cascade');
        $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
        $table->text('notes')->nullable();
        $table->timestamp('approval_at')->nullable();
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_approvals');
    }
};
