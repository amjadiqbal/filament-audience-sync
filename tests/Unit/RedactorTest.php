<?php

declare(strict_types=1);

use AmjadIqbal\FilamentAudienceSync\Support\Redactor;

it('removes configured secrets and anything shaped like a provider key', function (): void {
    $text = 'k=my-secret-value mc='.str_repeat('0a', 16).'-us21 brevo=xkey'.'sib-abc123-XyZ auth=Bearer abcdefghijklmnop';

    expect(Redactor::clean($text, ['my-secret-value', null, '']))
        ->toBe('k=[redacted] mc=[redacted] brevo=[redacted] auth=[redacted]');
});

it('caps long messages', function (): void {
    expect(mb_strlen(Redactor::clean(str_repeat('a', 900))))->toBe(500);
});
