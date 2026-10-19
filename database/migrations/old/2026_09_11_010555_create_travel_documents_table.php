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
        $table->foreignId('travel_request_id')->constrained('travel_requests')->onDelete('cascade');
        $table->string('document_type');
        $table->string('file_name');
        $table->string('file_path');
        $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
        $table->timestamp('uploaded_at')->useCurrent();
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
