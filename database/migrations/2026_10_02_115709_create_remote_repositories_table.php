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
        Schema::create('remote_repositories', function (Blueprint $table) {
            $table->string('external_id')->primary();
            $table->foreignId('git_source_id')->constrained()->cascadeOnDelete();
            $table->string('name')->collation('NOCASE');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('stars_count');
            $table->unsignedBigInteger('issues_count');
            $table->unsignedBigInteger('pull_requests_count');
            $table->unsignedBigInteger('forks_count');
            $table->string('language')->nullable();
            $table->boolean('archived');
            $table->timestamp('last_committed_at')->nullable();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();
            $table->index(['git_source_id', 'name', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remote_repositories');
    }
};
