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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('nik')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('manager_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('grade');
            $table->string('status')->default('Aktif');
            $table->string('avatar')->nullable();
            $table->string('whatsapp')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
