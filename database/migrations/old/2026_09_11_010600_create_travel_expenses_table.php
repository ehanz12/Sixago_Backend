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
        Schema::create('travel_expenses', function (Blueprint $table) {
        $table->id();
        $table->foreignId('travel_request_id')->constrained('travel_requests')->onDelete('cascade');
        $table->string('expense_type');
        $table->text('description')->nullable();
        $table->decimal('amount', 10, 2);
        $table->date('expense_date');
        $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_expenses');
    }
};
