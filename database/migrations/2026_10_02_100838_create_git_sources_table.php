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
        Schema::create('git_sources', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('remote_id');
            $table->string('account');
            $table->string('normalized_account');
            $table->string('name');
            $table->text('url');
            $table->text('avatar_url')->nullable();
            $table->string('account_type');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'remote_id']);
            $table->unique(['provider', 'normalized_account']);
            $table->index(['created_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('git_sources');
    }
};
