<?php

declare(strict_types=1);

// Amjad's rule: no brown in any UI. Filament draws badges and modal icons as translucent fills in dark mode, and a
// translucent amber, orange or red fill on a dark background reads as brown. Warm colours stay text, lines and borders.

it('keeps warm colours off badges, modal icons and charts in the source', function (): void {
    $source = collect(glob(__DIR__.'/../../src/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE))
        ->map(fn (string $file): string => (string) file_get_contents($file))
        ->implode("\n");

    expect($source)
        ->not->toMatch('/->badge\(/')
        ->not->toMatch("/modalIconColor\\('(warning|danger|primary)'\\)/")
        ->not->toMatch("/'warning'/")
        ->not->toMatch('/->chart\(/')
        // Buttons are solid fills; a red or primary-warm one dims to brown under a modal backdrop.
        ->not->toMatch("/->color\\('(danger|warning)'\\)/")
        ->not->toMatch('/\b(brown|tan|maroon|sienna|chocolate)\b/i');

    // Every confirmation modal sets a gray icon colour.
    expect(substr_count($source, "->modalIconColor('gray')"))->toBe(substr_count($source, '->requiresConfirmation()'));
});
