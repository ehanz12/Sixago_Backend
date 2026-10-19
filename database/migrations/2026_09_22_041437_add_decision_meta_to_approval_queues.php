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
        Schema::table('approval_queues', function (Blueprint $table) {
            $table->foreignId('decided_by')->nullable()->after('escalated')->constrained('users')->nullOnDelete();
            $table->foreignId('on_behalf_of')->nullable()->after('decided_by')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('on_behalf_of');
            $table->text('note')->nullable()->after('decided_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_queues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropConstrainedForeignId('on_behalf_of');
            $table->dropColumn(['decided_at', 'note']);
        });
    }
};
