<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the home page renders the empty application', function () {
    $this->withoutVite()
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home'));
});

test('the health endpoint responds successfully', function () {
    $this->get('/up')->assertOk();
});

test('starter pages are not exposed', function (string $path) {
    $this->get($path)->assertNotFound();
})->with([
    '/login',
    '/register',
    '/forgot-password',
    '/dashboard',
    '/settings',
    '/settings/profile',
    '/settings/security',
    '/settings/appearance',
]);
