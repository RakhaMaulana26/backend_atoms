<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_duties', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('duty_type')->constrained()->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('manager_duties', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};
