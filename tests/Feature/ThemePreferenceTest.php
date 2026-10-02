<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the theme defaults to system when no preference cookie exists', function () {
    $this->withoutVite();

    $response = $this->get(route('home'));

    $response
        ->assertSeeHtml('data-theme="system"')
        ->assertInertia(fn (Assert $page) => $page->where('theme', 'system'));
});

test('a browser theme cookie is shared with Inertia and rendered in the document', function (string $theme) {
    $this->withoutVite()->withUnencryptedCookie('theme', $theme);

    $response = $this->get(route('home'));

    $response
        ->assertSeeHtml('data-theme="'.$theme.'"')
        ->assertInertia(fn (Assert $page) => $page->where('theme', $theme));
})->with(['system', 'light', 'dark']);

test('an invalid theme cookie falls back to system', function (string $theme) {
    $this->withoutVite()->withUnencryptedCookie('theme', $theme);

    $response = $this->get(route('home'));

    $response
        ->assertSeeHtml('data-theme="system"')
        ->assertInertia(fn (Assert $page) => $page->where('theme', 'system'));
})->with([
    'empty' => '',
    'unsupported' => 'sepia',
    'wrong case' => 'DARK',
    'attribute injection' => 'dark" onload="alert(1)',
]);

test('an array theme cookie falls back to system', function () {
    $this->withoutVite()->withUnencryptedCookies(['theme' => ['dark']]);

    $response = $this->get(route('home'));

    $response
        ->assertSeeHtml('data-theme="system"')
        ->assertInertia(fn (Assert $page) => $page->where('theme', 'system'));
});
