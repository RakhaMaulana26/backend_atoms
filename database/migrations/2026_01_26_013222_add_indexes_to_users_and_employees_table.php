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
        // Add indexes to users table for faster queries
        Schema::table('users', function (Blueprint $table) {
            $table->index('email');
            $table->index('role');
            $table->index('is_active');
            $table->index('deleted_at');
            $table->index(['role', 'is_active']); // Composite index for common queries
        });

        // Add indexes to employees table
        Schema::table('employees', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('employee_type');
            $table->index('is_active');
            $table->index('deleted_at');
        });

        // Add indexes to account_tokens table
        Schema::table('account_tokens', function (Blueprint $table) {
            $table->index(['user_id', 'token']);
            $table->index('expired_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropIndex(['deleted_at']);
            $table->dropIndex(['role', 'is_active']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['employee_type']);
            $table->dropIndex(['is_active']);
            $table->dropIndex(['deleted_at']);
        });

        Schema::table('account_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'token']);
            $table->dropIndex(['expired_at']);
        });
    }
};
