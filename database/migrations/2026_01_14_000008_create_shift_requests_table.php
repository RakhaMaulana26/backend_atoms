<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('target_employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('from_roster_day_id')->constrained('roster_days')->onDelete('cascade');
            $table->foreignId('to_roster_day_id')->constrained('roster_days')->onDelete('cascade');
            $table->foreignId('shift_id')->constrained()->onDelete('cascade');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            
            $table->boolean('approved_by_target')->default(false);
            $table->boolean('approved_by_from_manager')->default(false);
            $table->boolean('approved_by_to_manager')->default(false);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Audit fields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_requests');
    }
};
