<?php

use App\Git\AccountType;
use App\Git\RemoteRepository;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Git\InMemoryGitProvider;

test('a source delegates to its own provider without imposing GitHub account naming rules', function () {
    Http::preventStrayRequests();
    $repository = new RemoteRepository(
        id: 'internal:42',
        name: 'app',
        fullName: 'team/subgroup/app',
        url: 'https://git.example.test/team/subgroup/app',
        description: null,
    );
    $provider = new InMemoryGitProvider([$repository]);
    $source = $provider->getSource('team/subgroup');

    $repositories = $source->getRepositories();

    expect($repositories)->toBe([$repository]);
    expect($provider->requestedSource)->toBe($source);
    expect($source->getProvider())->toBe($provider);
    expect($source->getName())->toBe('team/subgroup');
    expect($source->getAccountType())->toBe(AccountType::Organization);
    expect($source->getRemoteId())->toBe('42');
    expect($source->getDisplayName())->toBe('team/subgroup');
    expect($source->getUrl())->toBe('https://git.example.test/team/subgroup');
    expect($source->getAvatarUrl())->toBeNull();
    Http::assertNothingSent();
});
