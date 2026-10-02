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
        Schema::table('git_sources', function (Blueprint $table) {
            $table->string('normalized_name')->default('');
        });

        foreach (DB::table('git_sources')->select(['id', 'name'])->lazyById(200) as $source) {
            DB::table('git_sources')->where('id', $source->id)->update([
                'normalized_name' => mb_strtolower($source->name),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('git_sources', function (Blueprint $table) {
            $table->dropColumn('normalized_name');
        });
    }
};
