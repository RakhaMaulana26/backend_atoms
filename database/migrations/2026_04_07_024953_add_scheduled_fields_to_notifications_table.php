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
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('read_at');
            $table->enum('status', ['draft', 'pending', 'sent', 'failed'])->default('draft')->after('scheduled_at');
            $table->text('error_message')->nullable()->after('status');
            $table->json('recipient_ids')->nullable()->after('error_message');
            $table->index(['scheduled_at', 'status'], 'idx_notifications_scheduled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_scheduled');
            $table->dropColumn(['scheduled_at', 'status', 'error_message', 'recipient_ids']);
        });
    }
};
