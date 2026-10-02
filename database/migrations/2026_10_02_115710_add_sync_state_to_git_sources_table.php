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
            $table->string('sync_status')->default('idle');
            $table->string('last_sync_error_code')->nullable();
            $table->timestamp('last_sync_error_at')->nullable();
            $table->timestamp('sync_retry_at')->nullable();
            $table->uuid('sync_run_id')->nullable();
            $table->unsignedBigInteger('sync_revision')->default(0);
            $table->json('sync_checkpoint')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('git_sources', function (Blueprint $table) {
            $table->dropColumn(['sync_status', 'last_sync_error_code', 'last_sync_error_at', 'sync_retry_at', 'sync_run_id', 'sync_revision', 'sync_checkpoint']);
        });
    }
};
