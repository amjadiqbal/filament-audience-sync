<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Exceptions;

class RateLimitedException extends AudienceSyncException
{
    public function __construct(string $message, public readonly int $retryAfter = 60)
    {
        parent::__construct($message, 429);
    }
}
