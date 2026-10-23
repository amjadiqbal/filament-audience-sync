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

    /**
     * @return array{name: string, members: int, unsubscribed: ?int, open_rate: ?float, click_rate: ?float}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'members' => $this->members,
            'unsubscribed' => $this->unsubscribed,
            'open_rate' => $this->openRate,
            'click_rate' => $this->clickRate,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (int) ($data['members'] ?? 0),
            isset($data['unsubscribed']) ? (int) $data['unsubscribed'] : null,
            isset($data['open_rate']) ? (float) $data['open_rate'] : null,
            isset($data['click_rate']) ? (float) $data['click_rate'] : null,
        );
    }
}
