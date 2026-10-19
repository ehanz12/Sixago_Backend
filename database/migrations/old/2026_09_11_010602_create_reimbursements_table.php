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
        Schema::create('reimbursements', function (Blueprint $table) {
    $table->id();
    $table->foreignId('travel_request_id')->constrained('travel_requests')->onDelete('cascade');
    $table->decimal('total_expense', 10, 2);
    $table->decimal('advance_amount', 10, 2);
    $table->decimal('reimbursement_amount', 10, 2);
    $table->enum('status', ['submitted', 'verified', 'paid', 'rejected'])->default('submitted');
    $table->timestamp('submitted_at')->useCurrent();
    $table->foreignId('verified_by')->nullable()->constrained('users')->onDelete('set null');
    $table->timestamp('verified_at')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reimbursements');
    }
};
