<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Enums;

/**
 * What one sync operation did to one record.
 */
enum Outcome: string
{
    case Subscribed = 'subscribed';
    case Pending = 'pending';
    case Updated = 'updated';
    case Unsubscribed = 'unsubscribed';
    case SkippedUnsubscribed = 'skipped_unsubscribed';
    case SkippedNotMember = 'skipped_not_member';
    case SkippedNoEmail = 'skipped_no_email';
    case Failed = 'failed';

    public function label(): string
    {
        return __("filament-audience-sync::audience-sync.outcome.{$this->value}");
    }

    public function isSkipped(): bool
    {
        return str_starts_with($this->value, 'skipped_');
    }
}
