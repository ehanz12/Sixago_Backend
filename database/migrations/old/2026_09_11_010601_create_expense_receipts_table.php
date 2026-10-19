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
    Schema::create('expense_receipts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('expense_id')->constrained('travel_expenses')->onDelete('cascade');
        $table->string('file_name');
        $table->string('file_path');
        $table->timestamp('uploaded_at')->useCurrent();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_receipts');
    }
};
