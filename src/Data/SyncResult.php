<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Data;

use AmjadIqbal\FilamentAudienceSync\Enums\Outcome;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;

final class SyncResult
{
    public function __construct(
        public readonly Outcome $outcome,
        public readonly ?Status $status = null,
        public readonly ?string $error = null,
    ) {}
}
