<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_duties', function (Blueprint $table) {
            $table->string('duty_type')->after('employee_id'); // Manager Teknik, General Manager
        });
    }

    public function down(): void
    {
        Schema::table('manager_duties', function (Blueprint $table) {
            $table->dropColumn('duty_type');
        });
    }
};
