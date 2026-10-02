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
        Schema::table('git_sources', function (Blueprint $table) {
            $table->timestamp('marked_for_deletion_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('git_sources', function (Blueprint $table) {
            $table->dropColumn('marked_for_deletion_at');
        });
    }
};
