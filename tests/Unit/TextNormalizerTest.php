<?php

use App\Search\TextNormalizer;

it('lowercases and strips niqqud, gershayim and punctuation', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize('Air-Fryer קוּמפקטי, צ׳יפס!'))->toBe('air fryer קומפקטי ציפס');
});

it('writes final letters as regular ones, so a stripped plural matches', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize('סכין'))->toBe('סכינ')
        ->and($normalizer->variants($normalizer->normalize('סכינים')))->toHaveKey('סכינ');
});

it('drops stopwords from tokens', function () {
    $normalizer = new TextNormalizer(['משהו', 'של']);

    expect($normalizer->tokens('משהו לשערות של כלב'))->toBe(['לשערות', 'כלב']);
});

it('offers Hebrew forms without a prefix or a plural suffix', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->variants('השואב'))->toHaveKeys(['השואב', 'שואב'])
        ->and($normalizer->variants($normalizer->normalize('בלנדרים')))->toHaveKey('בלנדר')
        ->and($normalizer->variants('iphone'))->toBe(['iphone' => 1.0]);
});
