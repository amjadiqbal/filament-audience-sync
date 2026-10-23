<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Contracts;

/**
 * A model that can be synced to an audience. Use the SyncsWithAudience trait for sensible defaults.
 */
interface Subscribable
{
    public function audienceEmail(): ?string;

    /**
     * @return array<string, scalar|null> neutral keys (first_name, last_name, phone) or provider field names
     */
    public function audienceFields(): array;

    /**
     * @return list<string> tags added on subscribe and sync (never removed automatically)
     */
    public function audienceTags(): array;
}
