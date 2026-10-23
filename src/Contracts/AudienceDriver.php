<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Contracts;

use AmjadIqbal\FilamentAudienceSync\Data\Campaign;
use AmjadIqbal\FilamentAudienceSync\Data\Contact;
use AmjadIqbal\FilamentAudienceSync\Data\ListStats;
use AmjadIqbal\FilamentAudienceSync\Data\Member;

/**
 * One email-marketing provider, bound to one audience or list. Drivers talk to the provider only; the consent rules
 * (never resubscribe someone who unsubscribed) live in AudienceSyncManager, so every driver gets them.
 *
 * Methods throw AudienceSyncException (message already free of credentials), RateLimitedException when the provider
 * still refuses after the retries, and NotConfiguredException before any request when the key or list is missing.
 */
interface AudienceDriver
{
    public const FEATURE_TAGS = 'tags';

    public const FEATURE_CAMPAIGNS = 'campaigns';

    public const FEATURE_DOUBLE_OPT_IN = 'double_opt_in';

    public function name(): string;

    public function listId(): string;

    public function supports(string $feature): bool;

    /**
     * The contact's current state; Status::NotSubscribed when the provider does not know the address.
     */
    public function member(string $email): Member;

    /**
     * Add the contact to the list (or as pending, with double opt-in), with fields and tags. The manager calls this
     * only for an address that is not a member and never unsubscribed; $current is what member() just returned.
     */
    public function subscribe(Contact $contact, Member $current): Member;

    /**
     * Update fields and add tags of an existing member. Must never change the member's subscription status.
     */
    public function update(Contact $contact): Member;

    public function unsubscribe(string $email): Member;

    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function tag(string $email, array $add, array $remove = []): void;

    public function stats(): ListStats;

    /**
     * @return list<Campaign> newest first
     */
    public function campaigns(int $limit = 5): array;

    /**
     * @return array<string, string> list id => name (with member count)
     */
    public function lists(): array;
}
