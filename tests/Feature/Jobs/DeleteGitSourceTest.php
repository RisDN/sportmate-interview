<?php

use App\Jobs\DeleteGitSource;
use App\Models\GitSource;
use App\Models\RemoteRepository;
use App\Services\GitSourceService;
use App\Services\GitSourceSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

test('the worker skips a cancelled sync and deletes its source with every repository', function () {
    Http::preventStrayRequests();
    $source = app(GitSourceSyncService::class)->start(GitSource::factory()->create());
    RemoteRepository::factory()->count(12)->for($source, 'owner')->create();
    $unrelated = RemoteRepository::factory()->create();
    app(GitSourceService::class)->markForDeletion($source);
    app('queue.worker')->setCache(app('cache.store'));

    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));

    $this->assertModelMissing($source);
    $this->assertDatabaseMissing('remote_repositories', ['git_source_id' => $source->id]);
    $this->assertModelExists($unrelated);
    $this->assertDatabaseEmpty('jobs');
    $this->assertDatabaseEmpty('failed_jobs');
    Http::assertNothingSent();
});

test('cleanup never deletes an unmarked source and duplicate deliveries are harmless', function () {
    $source = GitSource::factory()->create();
    $repository = RemoteRepository::factory()->for($source, 'owner')->create();
    $deleted = GitSource::factory()->create(['marked_for_deletion_at' => now()]);
    $job = new DeleteGitSource($deleted->id);

    (new DeleteGitSource($source->id))->handle();
    $job->handle();
    $job->handle();

    $this->assertModelExists($source);
    $this->assertModelExists($repository);
    $this->assertModelMissing($deleted);
});
