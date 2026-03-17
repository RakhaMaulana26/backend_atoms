<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Make requester_shift_id and target_shift_id nullable since we now use notes as primary identifier
     */
    public function up(): void
    {
        // Drop existing foreign key constraints using raw SQL (constraint names from original migration)
        DB::statement('ALTER TABLE shift_requests DROP CONSTRAINT IF EXISTS shift_requests_shift_id_foreign');
        DB::statement('ALTER TABLE shift_requests DROP CONSTRAINT IF EXISTS shift_requests_target_shift_id_foreign');
        
        // Make columns nullable
        DB::statement('ALTER TABLE shift_requests ALTER COLUMN requester_shift_id DROP NOT NULL');
        DB::statement('ALTER TABLE shift_requests ALTER COLUMN target_shift_id DROP NOT NULL');

        // Re-add foreign key constraints with ON DELETE SET NULL
        Schema::table('shift_requests', function (Blueprint $table) {
            $table->foreign('requester_shift_id')
                ->references('id')
                ->on('shifts')
                ->nullOnDelete();
            
            $table->foreign('target_shift_id')
                ->references('id')
                ->on('shifts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_requests', function (Blueprint $table) {
            $table->dropForeign(['requester_shift_id']);
            $table->dropForeign(['target_shift_id']);
        });

        // Note: This may fail if there are null values in the database
        DB::statement('ALTER TABLE shift_requests ALTER COLUMN requester_shift_id SET NOT NULL');
        DB::statement('ALTER TABLE shift_requests ALTER COLUMN target_shift_id SET NOT NULL');

        Schema::table('shift_requests', function (Blueprint $table) {
            $table->foreign('requester_shift_id')
                ->references('id')
                ->on('shifts')
                ->onDelete('cascade');
            
            $table->foreign('target_shift_id')
                ->references('id')
                ->on('shifts')
                ->onDelete('cascade');
        });
    }
};
