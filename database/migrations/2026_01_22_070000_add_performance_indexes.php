<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add performance indexes for frequently queried columns
     */
    public function up(): void
    {
        // 1. roster_days - Query by roster_period_id and work_date
        Schema::table('roster_days', function (Blueprint $table) {
            $table->index('roster_period_id', 'idx_roster_days_period');
            $table->index('work_date', 'idx_roster_days_date');
            $table->index(['roster_period_id', 'work_date'], 'idx_roster_days_period_date');
        });

        // 2. shift_assignments - Query by roster_day_id, employee_id, shift_id
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->index('roster_day_id', 'idx_shift_assignments_day');
            $table->index('employee_id', 'idx_shift_assignments_employee');
            $table->index('shift_id', 'idx_shift_assignments_shift');
            // Composite index for duplicate checking (most common query)
            $table->index(['roster_day_id', 'employee_id', 'shift_id'], 'idx_shift_assignments_unique_check');
            $table->index('deleted_at', 'idx_shift_assignments_deleted'); // For soft delete queries
        });

        // 3. manager_duties - Query by roster_day_id, employee_id
        Schema::table('manager_duties', function (Blueprint $table) {
            $table->index('roster_day_id', 'idx_manager_duties_day');
            $table->index('employee_id', 'idx_manager_duties_employee');
            $table->index('duty_type', 'idx_manager_duties_type');
            // Composite index for duplicate checking
            $table->index(['roster_day_id', 'employee_id', 'duty_type'], 'idx_manager_duties_unique_check');
            $table->index('deleted_at', 'idx_manager_duties_deleted');
        });

        // 4. roster_periods - Query by status, month, year
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->index('status', 'idx_roster_periods_status');
            $table->index('deleted_at', 'idx_roster_periods_deleted');
        });

        // 5. employees - Query by employee_type, is_active
        Schema::table('employees', function (Blueprint $table) {
            $table->index('user_id', 'idx_employees_user');
            $table->index('employee_type', 'idx_employees_type');
            $table->index('is_active', 'idx_employees_active');
            $table->index(['employee_type', 'is_active'], 'idx_employees_type_active');
            $table->index('deleted_at', 'idx_employees_deleted');
        });

        // 6. users - Query by role, is_active, email
        Schema::table('users', function (Blueprint $table) {
            $table->index('role', 'idx_users_role');
            $table->index('is_active', 'idx_users_active');
            $table->index(['role', 'is_active'], 'idx_users_role_active');
            $table->index('deleted_at', 'idx_users_deleted');
        });

        // 7. shift_requests - Query by requester_employee_id, status, from_roster_day_id
        Schema::table('shift_requests', function (Blueprint $table) {
            $table->index('requester_employee_id', 'idx_shift_requests_requester');
            $table->index('target_employee_id', 'idx_shift_requests_target');
            $table->index('status', 'idx_shift_requests_status');
            $table->index('from_roster_day_id', 'idx_shift_requests_from_day');
            $table->index('to_roster_day_id', 'idx_shift_requests_to_day');
            $table->index(['requester_employee_id', 'status'], 'idx_shift_requests_requester_status');
            $table->index('deleted_at', 'idx_shift_requests_deleted');
        });

        // 8. notifications - Query by user_id, is_read, created_at
        Schema::table('notifications', function (Blueprint $table) {
            $table->index('user_id', 'idx_notifications_user');
            $table->index('is_read', 'idx_notifications_read');
            $table->index(['user_id', 'is_read'], 'idx_notifications_user_read');
            $table->index('created_at', 'idx_notifications_created');
        });

        // 9. activity_logs - Query by user_id, module, created_at
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('user_id', 'idx_activity_logs_user');
            $table->index('module', 'idx_activity_logs_module');
            $table->index('action', 'idx_activity_logs_action');
            $table->index('created_at', 'idx_activity_logs_created');
            $table->index(['module', 'action'], 'idx_activity_logs_module_action');
        });

        // 10. shifts - Query by name
        Schema::table('shifts', function (Blueprint $table) {
            $table->index('deleted_at', 'idx_shifts_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roster_days', function (Blueprint $table) {
            $table->dropIndex('idx_roster_days_period');
            $table->dropIndex('idx_roster_days_date');
            $table->dropIndex('idx_roster_days_period_date');
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropIndex('idx_shift_assignments_day');
            $table->dropIndex('idx_shift_assignments_employee');
            $table->dropIndex('idx_shift_assignments_shift');
            $table->dropIndex('idx_shift_assignments_unique_check');
            $table->dropIndex('idx_shift_assignments_deleted');
        });

        Schema::table('manager_duties', function (Blueprint $table) {
            $table->dropIndex('idx_manager_duties_day');
            $table->dropIndex('idx_manager_duties_employee');
            $table->dropIndex('idx_manager_duties_type');
            $table->dropIndex('idx_manager_duties_unique_check');
            $table->dropIndex('idx_manager_duties_deleted');
        });

        Schema::table('roster_periods', function (Blueprint $table) {
            $table->dropIndex('idx_roster_periods_status');
            $table->dropIndex('idx_roster_periods_deleted');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('idx_employees_user');
            $table->dropIndex('idx_employees_type');
            $table->dropIndex('idx_employees_active');
            $table->dropIndex('idx_employees_type_active');
            $table->dropIndex('idx_employees_deleted');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_role');
            $table->dropIndex('idx_users_active');
            $table->dropIndex('idx_users_role_active');
            $table->dropIndex('idx_users_deleted');
        });

        Schema::table('shift_requests', function (Blueprint $table) {
            $table->dropIndex('idx_shift_requests_requester');
            $table->dropIndex('idx_shift_requests_target');
            $table->dropIndex('idx_shift_requests_status');
            $table->dropIndex('idx_shift_requests_from_day');
            $table->dropIndex('idx_shift_requests_to_day');
            $table->dropIndex('idx_shift_requests_requester_status');
            $table->dropIndex('idx_shift_requests_deleted');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_user');
            $table->dropIndex('idx_notifications_read');
            $table->dropIndex('idx_notifications_user_read');
            $table->dropIndex('idx_notifications_created');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('idx_activity_logs_user');
            $table->dropIndex('idx_activity_logs_module');
            $table->dropIndex('idx_activity_logs_action');
            $table->dropIndex('idx_activity_logs_created');
            $table->dropIndex('idx_activity_logs_module_action');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex('idx_shifts_deleted');
        });
    }
};
