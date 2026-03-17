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
        // First drop the foreign key constraint
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
        });

        // Make shift_id nullable
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->change();
        });

        // Re-add foreign key constraint with SET NULL on delete
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts')
                ->onDelete('set null');
        });

        // Make notes required (not null) - update existing null values first
        DB::statement("UPDATE shift_assignments SET notes = 'L' WHERE notes IS NULL");
        
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->string('notes', 50)->nullable(false)->default('L')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the new foreign key
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
        });

        // Make shift_id required again
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable(false)->change();
        });

        // Re-add original foreign key constraint
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts')
                ->onDelete('cascade');
        });

        // Make notes nullable again
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->string('notes', 50)->nullable()->change();
        });
    }
};
