<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Data;

use Carbon\CarbonImmutable;

/**
 * A sent campaign and its report. Rates are percentages, null when the provider has no report yet.
 */
final class Campaign
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?CarbonImmutable $sentAt,
        public readonly int $recipients,
        public readonly ?float $openRate,
        public readonly ?float $clickRate,
    ) {}

    /**
     * @return array{id: string, title: string, sent_at: ?string, recipients: int, open_rate: ?float, click_rate: ?float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'sent_at' => $this->sentAt?->toIso8601String(),
            'recipients' => $this->recipients,
            'open_rate' => $this->openRate,
            'click_rate' => $this->clickRate,
        ];
    }
}
