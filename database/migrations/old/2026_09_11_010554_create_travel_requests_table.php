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
        Schema::create('travel_requests', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
        $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('set null');
        $table->foreignId('policy_id')->constrained('travel_policies')->onDelete('cascade');
        $table->text('purpose');
        $table->string('destination');
        $table->date('start_date');
        $table->date('end_date');
        $table->decimal('estimated_cost', 10, 2);
        $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'cancelled'])->default('draft');
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_requests');
    }
};
