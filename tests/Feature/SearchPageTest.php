<?php

use Illuminate\Support\Facades\File;

it('renders the search page in Hebrew, right to left', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<html lang="he" dir="rtl">', escape: false)
        ->assertSee('מה בא לכם למצוא?')
        ->assertSee('role="search"', escape: false);
});

it('passes the Hebrew messages to the page script', function () {
    $this->get('/')
        ->assertSee('productSearch(', escape: false)
        ->assertSee('משהו השתבש. נסו שוב.');
});

it('has a Hebrew translation for every string on the page', function () {
    $translations = json_decode(File::get(lang_path('he.json')), true, flags: JSON_THROW_ON_ERROR);

    preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)+)'\\)/", File::get(resource_path('views/search.blade.php')), $matches);

    $missing = array_values(array_diff(array_unique($matches[1]), array_keys($translations)));

    expect($matches[1])->not->toBeEmpty()
        ->and($missing)->toBe([]);
});
