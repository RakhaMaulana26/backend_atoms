<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_period_id')->constrained()->onDelete('cascade');
            $table->date('work_date');
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
        Schema::dropIfExists('roster_days');
    }
};
