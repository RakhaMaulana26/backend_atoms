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
            // Add notes columns for flexible shift identification
            $table->string('requester_notes', 50)->nullable()->after('requester_shift_id');
            $table->string('target_notes', 50)->nullable()->after('target_shift_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_requests', function (Blueprint $table) {
            $table->dropColumn(['requester_notes', 'target_notes']);
        });
    }
};
