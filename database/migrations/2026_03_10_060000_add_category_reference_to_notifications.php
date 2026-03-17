<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add category and reference_id to notifications for action-based notifications like shift_request
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('category', 50)->nullable()->after('type')->index();
            $table->unsignedBigInteger('reference_id')->nullable()->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['category', 'reference_id']);
        });
    }
};
