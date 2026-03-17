<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            
            // Request type: doctor_leave, annual_leave, external_duty, educational_assignment
            $table->enum('request_type', ['doctor_leave', 'annual_leave', 'external_duty', 'educational_assignment']);
            
            // Date range
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('total_days')->default(1);
            
            // General fields
            $table->text('reason')->nullable(); // For doctor_leave, annual_leave
            
            // External duty & Educational assignment fields
            $table->string('institution', 255)->nullable(); // Institution/location for external_duty & educational_assignment
            $table->string('education_type', 100)->nullable(); // Type of education (Diklat, S1, S2, S3, etc.)
            $table->string('program_course', 255)->nullable(); // Program/course name
            
            // Document attachment
            $table->string('document_path', 500)->nullable();
            
            // Approval workflow
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by_manager_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->text('approval_notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Audit fields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            
            // Indexes for performance
            $table->index('employee_id');
            $table->index('status');
            $table->index(['start_date', 'end_date']);
            $table->index('request_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
