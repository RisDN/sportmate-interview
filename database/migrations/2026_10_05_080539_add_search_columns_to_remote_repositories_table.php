<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('remote_repositories', function (Blueprint $table) {
            $table->string('normalized_name')->default('');
            $table->text('normalized_description')->nullable();
        });

        foreach (DB::table('remote_repositories')->select(['external_id', 'name', 'description'])->lazyById(200, 'external_id') as $repository) {
            DB::table('remote_repositories')->where('external_id', $repository->external_id)->update([
                'normalized_name' => mb_strtolower($repository->name),
                'normalized_description' => $repository->description === null ? null : mb_strtolower($repository->description),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('remote_repositories', function (Blueprint $table) {
            $table->dropColumn(['normalized_name', 'normalized_description']);
        });
    }
};
