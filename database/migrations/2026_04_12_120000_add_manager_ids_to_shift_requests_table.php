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
        Schema::table('shift_requests', function (Blueprint $table) {
            $table->foreignId('from_manager_id')
                ->nullable()
                ->after('approved_by_target')
                ->constrained('employees')
                ->nullOnDelete();

            $table->foreignId('to_manager_id')
                ->nullable()
                ->after('from_manager_id')
                ->constrained('employees')
                ->nullOnDelete();

            $table->index('from_manager_id', 'idx_shift_requests_from_manager');
            $table->index('to_manager_id', 'idx_shift_requests_to_manager');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_requests', function (Blueprint $table) {
            $table->dropIndex('idx_shift_requests_from_manager');
            $table->dropIndex('idx_shift_requests_to_manager');
            $table->dropConstrainedForeignId('from_manager_id');
            $table->dropConstrainedForeignId('to_manager_id');
        });
    }
};
