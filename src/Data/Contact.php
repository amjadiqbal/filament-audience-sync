<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Data;

/**
 * What is sent to the provider for one person. Fields use neutral keys (first_name, last_name, ...) that each driver
 * maps to its own merge fields or attributes.
 */
final class Contact
{
    /**
     * @param  array<string, scalar|null>  $fields
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $email,
        public readonly array $fields = [],
        public readonly array $tags = [],
    ) {}
}
