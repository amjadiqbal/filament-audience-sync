<?php

declare(strict_types=1);

return [

    /*
    | The driver used when none is named: "mailchimp", "brevo", or a driver you registered with AudienceSync::extend().
    */
    'default' => env('AUDIENCE_SYNC_DRIVER', 'mailchimp'),

    /*
    | One entry per driver. API keys come from your .env and are never stored in the database, shown in the panel or
    | written to logs. "list" is the Mailchimp audience ID or the Brevo list ID (php artisan audience-sync:lists).
    | "fields" maps the neutral keys your model returns from audienceFields() to the provider's merge fields or
    | attributes; keys not in the map are sent unchanged.
    */
    'drivers' => [

        'mailchimp' => [
            'api_key' => env('MAILCHIMP_API_KEY'),
            'list' => env('MAILCHIMP_LIST_ID'),
            // The data centre ("us21") is read from the end of the API key; set it here only if your key has none.
            'server' => env('MAILCHIMP_SERVER'),
            // true: new contacts get Mailchimp's confirmation email and stay "pending" until they confirm.
            'double_opt_in' => (bool) env('MAILCHIMP_DOUBLE_OPT_IN', false),
            'fields' => [
                'first_name' => 'FNAME',
                'last_name' => 'LNAME',
                'phone' => 'PHONE',
            ],
        ],

        'brevo' => [
            'api_key' => env('BREVO_API_KEY'),
            'list' => env('BREVO_LIST_ID'),
            // "blocklist": unsubscribing blocks the contact from all marketing email in Brevo, as Brevo's own
            // unsubscribe link does. "unlink": unsubscribing only removes the contact from this list.
            'unsubscribe' => env('BREVO_UNSUBSCRIBE_MODE', 'blocklist'),
            'fields' => [
                'first_name' => 'FIRSTNAME',
                'last_name' => 'LASTNAME',
                'phone' => 'SMS',
            ],
        ],

    ],

    /*
    | Bulk actions split the selected records into chunks and push one job per chunk.
    */
    'queue' => [
        'connection' => env('AUDIENCE_SYNC_QUEUE_CONNECTION'),
        'name' => env('AUDIENCE_SYNC_QUEUE'),
        'chunk' => 50,
    ],

    /*
    | Rate limits: a 429 or 5xx answer is retried after the provider's Retry-After, else after an exponential backoff
    | starting at "backoff_ms". After "retries" attempts the request fails; a queued chunk is released and tried later.
    */
    'http' => [
        'timeout' => 15,
        'retries' => 3,
        'backoff_ms' => 500,
        'max_wait_seconds' => 20,
    ],

    /*
    | How long list stats and recent campaigns are cached, in seconds. 0 turns the cache off.
    */
    'cache_seconds' => 300,

];
