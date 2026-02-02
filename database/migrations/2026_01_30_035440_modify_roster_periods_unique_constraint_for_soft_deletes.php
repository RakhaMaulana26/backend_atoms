<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Mengubah unique constraint agar hanya berlaku untuk data yang tidak di-soft delete.
     * PostgreSQL menggunakan partial unique index dengan kondisi WHERE deleted_at IS NULL.
     */
    public function up(): void
    {
        // 1. Roster Periods - unique (month, year) hanya untuk data aktif
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->dropUnique(['month', 'year']);
        });
        
        DB::statement('
            CREATE UNIQUE INDEX roster_periods_month_year_unique 
            ON roster_periods (month, year) 
            WHERE deleted_at IS NULL
        ');

        // 2. Users - unique email hanya untuk data aktif
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
        
        DB::statement('
            CREATE UNIQUE INDEX users_email_unique 
            ON users (email) 
            WHERE deleted_at IS NULL
        ');

        // 3. Account Tokens - unique token hanya untuk data aktif
        Schema::table('account_tokens', function (Blueprint $table) {
            $table->dropUnique(['token']);
        });
        
        DB::statement('
            CREATE UNIQUE INDEX account_tokens_token_unique 
            ON account_tokens (token) 
            WHERE deleted_at IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Roster Periods - kembalikan ke unique biasa
        DB::statement('DROP INDEX IF EXISTS roster_periods_month_year_unique');
        
        Schema::table('roster_periods', function (Blueprint $table) {
            $table->unique(['month', 'year']);
        });

        // 2. Users - kembalikan ke unique biasa
        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['email']);
        });

        // 3. Account Tokens - kembalikan ke unique biasa
        DB::statement('DROP INDEX IF EXISTS account_tokens_token_unique');
        
        Schema::table('account_tokens', function (Blueprint $table) {
            $table->unique(['token']);
        });
    }
};
