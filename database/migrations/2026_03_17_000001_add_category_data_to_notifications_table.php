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
        $hasCategory = Schema::hasColumn('notifications', 'category');
        $hasData = Schema::hasColumn('notifications', 'data');

        if ($hasCategory && $hasData) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) use ($hasCategory, $hasData) {
            if (!$hasCategory) {
                $table->string('category')->nullable()->after('type');
            }

            if (!$hasData) {
                $table->text('data')->nullable()->after('category');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('notifications', 'data')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('data');
            });
        }
    }
};
