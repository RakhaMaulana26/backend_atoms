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
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->longText('document_content')->nullable()->after('document_path');
            $table->string('document_mime_type', 120)->nullable()->after('document_content');
            $table->string('document_original_name', 255)->nullable()->after('document_mime_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['document_content', 'document_mime_type', 'document_original_name']);
        });
    }
};
