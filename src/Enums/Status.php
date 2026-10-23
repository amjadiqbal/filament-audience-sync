<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Enums;

/**
 * A contact's state in the provider's audience or list, in provider-neutral terms.
 */
enum Status: string
{
    case Subscribed = 'subscribed';
    case Pending = 'pending';
    case Unsubscribed = 'unsubscribed';
    case Cleaned = 'cleaned';
    case Archived = 'archived';
    case NotSubscribed = 'not_subscribed';

    /**
     * The contact said no, the provider stopped mailing them, or the contact was archived there: never subscribe them
     * again from this plugin.
     */
    public function blocksSubscribe(): bool
    {
        // Archived: someone removed the contact in the provider, possibly after an unsubscribe. Restore it there.
        return in_array($this, [self::Unsubscribed, self::Cleaned, self::Archived], true);
    }

    public function label(): string
    {
        return __("filament-audience-sync::audience-sync.status.{$this->value}");
    }

    /**
     * A Filament colour for solid text. No warm colours: those are kept for errors only.
     */
    public function color(): string
    {
        return match ($this) {
            self::Subscribed => 'success',
            self::Pending => 'info',
            default => 'gray',
        };
    }
}
