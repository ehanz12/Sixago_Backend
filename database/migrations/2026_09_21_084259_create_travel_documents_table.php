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
        Schema::create('travel_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_request_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('tiket'); // tiket | hotel | nota | pendukung
            $table->string('title');
            $table->string('file_path')->nullable();
            $table->string('file_url')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_kb')->nullable();
            $table->string('ocr_status')->default('Valid');
            $table->decimal('amount', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_documents');
    }
};
