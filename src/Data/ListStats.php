<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Data;

/**
 * Basic numbers for the configured audience or list. Rates are percentages (42.5 = 42.5%), null when unknown.
 */
final class ListStats
{
    public function __construct(
        public readonly string $name,
        public readonly int $members,
        public readonly ?int $unsubscribed = null,
        public readonly ?float $openRate = null,
        public readonly ?float $clickRate = null,
    ) {}
}
