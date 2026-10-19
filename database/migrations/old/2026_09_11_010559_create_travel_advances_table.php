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
        Schema::create('travel_advances', function (Blueprint $table) {
        $table->id();
        $table->foreignId('travel_request_id')->constrained('travel_requests')->onDelete('cascade');
        $table->decimal('amount', 10, 2);
        $table->date('request_date');
        $table->decimal('approved_amount', 10, 2)->default(0);
        $table->enum('status', ['requested', 'approved', 'paid', 'rejected'])->default('requested');
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_advances');
    }
};
