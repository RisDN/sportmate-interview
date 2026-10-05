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
            $table->unsignedBigInteger('repositories_revision')->default(0);
        });

        // SQLite triggers keep cache generations atomic with all writes, including transfers and bulk SQL.
        DB::unprepared('CREATE TRIGGER remote_repositories_insert_revision AFTER INSERT ON remote_repositories
            BEGIN
                UPDATE git_sources SET repositories_revision = repositories_revision + 1 WHERE id = NEW.git_source_id;
            END');
        DB::unprepared('CREATE TRIGGER remote_repositories_update_revision AFTER UPDATE ON remote_repositories
            BEGIN
                UPDATE git_sources SET repositories_revision = repositories_revision + 1 WHERE id IN (OLD.git_source_id, NEW.git_source_id);
            END');
        DB::unprepared('CREATE TRIGGER remote_repositories_delete_revision AFTER DELETE ON remote_repositories
            BEGIN
                UPDATE git_sources SET repositories_revision = repositories_revision + 1 WHERE id = OLD.git_source_id;
            END');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS remote_repositories_insert_revision');
        DB::unprepared('DROP TRIGGER IF EXISTS remote_repositories_update_revision');
        DB::unprepared('DROP TRIGGER IF EXISTS remote_repositories_delete_revision');

        Schema::table('git_sources', fn (Blueprint $table) => $table->dropColumn('repositories_revision'));
    }
};
