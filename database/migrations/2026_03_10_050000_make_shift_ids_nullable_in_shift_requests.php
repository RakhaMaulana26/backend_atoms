<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite doesn't support altering constraints like this
            return;
        }

        DB::statement('ALTER TABLE shift_requests DROP CONSTRAINT IF EXISTS shift_requests_shift_id_foreign');
        DB::statement('ALTER TABLE shift_requests DROP CONSTRAINT IF EXISTS shift_requests_target_shift_id_foreign');
        
        DB::statement('ALTER TABLE shift_requests ALTER COLUMN requester_shift_id DROP NOT NULL');
        DB::statement('ALTER TABLE shift_requests ALTER COLUMN target_shift_id DROP NOT NULL');

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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('shift_requests', function (Blueprint $table) {
            $table->dropForeign(['requester_shift_id']);
            $table->dropForeign(['target_shift_id']);
        });

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