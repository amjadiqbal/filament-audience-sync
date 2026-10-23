<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Support;

/**
 * Removes credentials from any text before it is shown, stored or reported. Provider error bodies can echo parts of a
 * request, so every message from a provider goes through here.
 */
final class Redactor
{
    private const PATTERNS = [
        '/\b[0-9a-f]{32}-[a-z]{2,4}\d{1,3}\b/i', // Mailchimp API key
        '/\bxkeysib-[A-Za-z0-9-]+/', // Brevo API key
        '/\bxsmtpsib-[A-Za-z0-9-]+/', // Brevo SMTP key
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i',
    ];

    /**
     * @param  array<int, mixed>  $secrets  exact values to remove (the configured keys)
     */
    public static function clean(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 6) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        $text = (string) preg_replace(self::PATTERNS, '[redacted]', $text);

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 497).'...' : $text;
    }
}
