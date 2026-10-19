<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index pendukung pruning.
 *
 * Tanpa ini, `audit:prune` dan `notifications:prune` memindai seluruh tabel
 * setiap malam. audit_logs tidak punya index apa pun pada created_at, padahal
 * itu satu-satunya kolom yang dipakai kedua perintah tersebut untuk batas usia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at', 'audit_logs_created_at_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'read'], 'notifications_user_id_read_index');
            $table->index('created_at', 'notifications_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_created_at_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_id_read_index');
            $table->dropIndex('notifications_created_at_index');
        });
    }
};