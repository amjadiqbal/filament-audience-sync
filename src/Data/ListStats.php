<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Data;

/**
 * Basic numbers for the configured audience or list. Rates are percentages (42.5 = 42.5%), null when unknown.
 * $rateCampaigns: 0 when the rates are the provider's own audience averages, else how many recent campaigns were
 * averaged. $unsubscribedIsBlocklist: the unsubscribed count is the list's blocklisted contacts (Brevo).
 */
final class ListStats
{
    public function __construct(
        public readonly string $name,
        public readonly int $members,
        public readonly ?int $unsubscribed = null,
        public readonly ?float $openRate = null,
        public readonly ?float $clickRate = null,
        public readonly int $rateCampaigns = 0,
        public readonly bool $unsubscribedIsBlocklist = false,
    ) {}

    /**
     * @return array{name: string, members: int, unsubscribed: ?int, open_rate: ?float, click_rate: ?float, rate_campaigns: int, unsubscribed_is_blocklist: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'members' => $this->members,
            'unsubscribed' => $this->unsubscribed,
            'open_rate' => $this->openRate,
            'click_rate' => $this->clickRate,
            'rate_campaigns' => $this->rateCampaigns,
            'unsubscribed_is_blocklist' => $this->unsubscribedIsBlocklist,
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
            (int) ($data['rate_campaigns'] ?? 0),
            (bool) ($data['unsubscribed_is_blocklist'] ?? false),
        );
    }
}
