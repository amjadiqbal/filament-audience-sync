<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Exceptions;

use RuntimeException;

/**
 * A provider error. The message has been passed through the Redactor, so it is safe to show and to report.
 */
class AudienceSyncException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
