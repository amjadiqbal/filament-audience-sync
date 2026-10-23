<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Data;

use AmjadIqbal\FilamentAudienceSync\Enums\Status;

/**
 * A contact as the provider sees it.
 */
final class Member
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $email,
        public readonly Status $status,
        public readonly ?string $providerId = null,
        public readonly array $tags = [],
        public readonly ?string $providerStatus = null,
    ) {}

    public static function absent(string $email): self
    {
        return new self($email, Status::NotSubscribed);
    }
}
