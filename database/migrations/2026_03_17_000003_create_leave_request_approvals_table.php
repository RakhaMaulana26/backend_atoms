<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->onDelete('cascade');
            $table->foreignId('roster_day_id')->nullable()->constrained('roster_days')->nullOnDelete();
            $table->date('work_date');
            $table->string('employee_shift_notes', 50)->nullable();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('approval_notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['leave_request_id', 'work_date'], 'leave_request_approvals_leave_date_unique');
            $table->index(['manager_employee_id', 'status'], 'leave_request_approvals_manager_status_idx');
            $table->index(['work_date', 'status'], 'leave_request_approvals_work_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_approvals');
    }
};
