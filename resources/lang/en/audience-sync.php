<?php

declare(strict_types=1);

return [
    'status' => [
        'subscribed' => 'Subscribed',
        'pending' => 'Pending confirmation',
        'unsubscribed' => 'Unsubscribed',
        'cleaned' => 'Cleaned (bounced)',
        'archived' => 'Archived in provider',
        'not_subscribed' => 'Not subscribed',
    ],

    'outcome' => [
        'subscribed' => 'Subscribed',
        'pending' => 'Confirmation email sent',
        'updated' => 'Synced',
        'unsubscribed' => 'Unsubscribed',
        'skipped_unsubscribed' => 'Not subscribed: this person unsubscribed',
        'skipped_not_member' => 'Not in the list',
        'skipped_no_email' => 'No valid email address',
        'failed' => 'Sync failed',
    ],

    'column' => [
        'label' => 'Mailing list',
        'not_synced' => 'Not synced',
        'synced' => 'Synced :when',
        'tags' => 'Tags: :tags',
        'last_error' => 'Last error: :error',
    ],

    'actions' => [
        'group' => 'Mailing list',
        'subscribe' => 'Subscribe',
        'subscribe_blocked' => 'This person unsubscribed or was removed. They can only rejoin through the provider\'s own signup form.',
        'update' => 'Sync details',
        'unsubscribe' => 'Unsubscribe',
        'unsubscribe_heading' => 'Unsubscribe from the mailing list?',
        'unsubscribe_description' => 'They stop receiving campaigns. The choice is recorded, and this panel will never subscribe them again.',
        'tags' => 'Edit tags',
        'tags_submit' => 'Save tags',
        'tags_add' => 'Add tags',
        'tags_remove' => 'Remove tags',
        'bulk_subscribe' => 'Subscribe selected',
        'bulk_subscribe_description' => 'Queues the selected records. Anyone who unsubscribed is skipped.',
        'bulk_update' => 'Sync details of selected',
        'bulk_update_description' => 'Queues the selected records. Only existing members are updated; no one is subscribed.',
        'bulk_unsubscribe' => 'Unsubscribe selected',
        'bulk_unsubscribe_description' => 'Queues the selected records to be unsubscribed. They will never be subscribed again from this panel.',
    ],

    'notifications' => [
        'queued' => '{1} 1 record queued|[2,*] :count records queued',
        'queued_body' => 'A queue worker syncs them in the background; the status column updates as each one finishes.',
        'status' => 'Status: :status',
        'skipped_unsubscribed' => 'They unsubscribed, so they are not subscribed again. Consent comes first.',
        'skipped_not_member' => 'Subscribe them first; sync only updates existing members.',
        'skipped_no_email' => 'The record has no valid email address.',
    ],

    'stats' => [
        'heading' => 'Mailing list',
        'members' => 'Members',
        'unsubscribed' => 'Unsubscribed',
        'unsubscribed_help' => 'All time',
        'open_rate' => 'Average open rate',
        'click_rate' => 'Average click rate',
        'unsubscribed_blocklist' => 'Blocklisted contacts in the list',
        'rate_audience' => 'Audience average',
        'rate_campaigns' => '{1} Last campaign to this list|[2,*] Last :count campaigns to this list',
        'unavailable' => 'List stats unavailable',
    ],

    'campaigns' => [
        'heading' => 'Recent campaigns',
        'title' => 'Campaign',
        'sent_at' => 'Sent',
        'recipients' => 'Recipients',
        'open_rate' => 'Opens',
        'click_rate' => 'Clicks',
        'empty' => 'No sent campaigns yet',
        'unavailable' => 'Campaigns unavailable',
    ],

    'command' => [
        'none' => 'No lists found for this API key.',
    ],

    'errors' => [
        'no_key' => 'No API key set for the :driver driver. Add it to your .env file.',
        'no_list' => 'No list or audience id set for the :driver driver. Run php artisan audience-sync:lists to find it.',
        'mailchimp_server' => 'The Mailchimp API key has no data centre suffix (like -us21). Set MAILCHIMP_SERVER.',
        'connection' => 'Could not reach :driver. Try again shortly.',
        'rate_limited' => ':driver is rate limiting requests. Try again in a moment; queued syncs retry by themselves.',
        'http' => 'The provider answered with HTTP :status.',
        'not_member' => 'This address is not a member of the list.',
        'no_tags' => 'The :driver driver does not support tags.',
        'unexpected' => 'Something went wrong. The error was reported to the application log.',
    ],
];
