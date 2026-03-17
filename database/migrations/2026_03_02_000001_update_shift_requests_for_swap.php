<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_requests', function (Blueprint $table) {
            // Rename shift_id to requester_shift_id for clarity
            $table->renameColumn('shift_id', 'requester_shift_id');
        });

        Schema::table('shift_requests', function (Blueprint $table) {
            // Add target_shift_id column
            $table->foreignId('target_shift_id')
                ->after('requester_shift_id')
                ->constrained('shifts')
                ->onDelete('cascade');
            
            // Add cancelled_at for tracking cancellation
            $table->timestamp('cancelled_at')->nullable()->after('status');
            
            // Add cancelled_by to track who cancelled
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            
            // Add rejection_reason
            $table->text('rejection_reason')->nullable()->after('cancelled_by');
            
            // Add swap_executed_at timestamp to track when swap was done
            $table->timestamp('swap_executed_at')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('shift_requests', function (Blueprint $table) {
            $table->dropForeign(['target_shift_id']);
            $table->dropColumn(['target_shift_id', 'cancelled_at', 'cancelled_by', 'rejection_reason', 'swap_executed_at']);
        });

        Schema::table('shift_requests', function (Blueprint $table) {
            $table->renameColumn('requester_shift_id', 'shift_id');
        });
    }
};
