<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Drivers;

use AmjadIqbal\FilamentAudienceSync\Contracts\AudienceDriver;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use AmjadIqbal\FilamentAudienceSync\Exceptions\NotConfiguredException;
use AmjadIqbal\FilamentAudienceSync\Exceptions\RateLimitedException;
use AmjadIqbal\FilamentAudienceSync\Support\Redactor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Base for REST drivers: authentication, retries with backoff on 429/5xx/connection errors, and error messages free
 * of credentials. Extend it for another provider (MailerLite, ...) and register it with AudienceSync::extend().
 */
abstract class HttpDriver implements AudienceDriver
{
    /**
     * @param  array<string, mixed>  $config  the driver's entry in config('audience-sync.drivers')
     */
    public function __construct(protected string $name, protected array $config) {}

    abstract protected function baseUrl(): string;

    abstract protected function authenticate(PendingRequest $request, string $apiKey): PendingRequest;

    /**
     * The provider's own error text from a failed response (before redaction).
     */
    abstract protected function errorMessage(Response $response): string;

    public function name(): string
    {
        return $this->name;
    }

    public function listId(): string
    {
        $list = trim((string) ($this->config['list'] ?? ''));

        if ($list === '') {
            throw new NotConfiguredException(__('filament-audience-sync::audience-sync.errors.no_list', ['driver' => $this->name]));
        }

        return $list;
    }

    protected function apiKey(): string
    {
        $key = trim((string) ($this->config['api_key'] ?? ''));

        if ($key === '') {
            throw new NotConfiguredException(__('filament-audience-sync::audience-sync.errors.no_key', ['driver' => $this->name]));
        }

        return $key;
    }

    /**
     * @param  'get'|'post'|'put'|'patch'|'delete'  $method
     * @param  array<string, mixed>  $data  query for GET, JSON body otherwise
     */
    protected function request(string $method, string $path, array $data = []): Response
    {
        $key = $this->apiKey();
        $http = (array) config('audience-sync.http', []);
        $retries = max(0, (int) ($http['retries'] ?? 3));
        $backoffMs = max(1, (int) ($http['backoff_ms'] ?? 500));
        $maxWait = max(1, (int) ($http['max_wait_seconds'] ?? 20));
        $attempt = 0;
        $waited = 0.0;

        while (true) {
            $response = null;

            try {
                $pending = $this->authenticate(
                    Http::baseUrl($this->baseUrl())->acceptJson()->asJson()->timeout((int) ($http['timeout'] ?? 15)),
                    $key,
                );
                $response = $pending->{$method}($path, $data);
            } catch (ConnectionException) {
                // Retried below like a 5xx.
            }

            if ($response !== null && $response->status() !== 429 && ! $response->serverError()) {
                return $response;
            }

            $attempt++;
            $wait = $this->retryAfter($response) ?? ($backoffMs * (2 ** ($attempt - 1))) / 1000;

            // max_wait_seconds caps the total time slept inside this one call, across all retries.
            if ($attempt > $retries || $waited + $wait > $maxWait) {
                if ($response === null) {
                    throw new AudienceSyncException(__('filament-audience-sync::audience-sync.errors.connection', ['driver' => $this->name]));
                }

                if ($response->status() === 429) {
                    throw new RateLimitedException(
                        __('filament-audience-sync::audience-sync.errors.rate_limited', ['driver' => $this->name]),
                        (int) min(3600, max(1, ceil($wait))),
                    );
                }

                throw $this->failure($response);
            }

            Sleep::for((int) round($wait * 1000))->milliseconds();
            $waited += $wait;
        }
    }

    /**
     * Seconds to wait, from Retry-After (seconds or an HTTP date) or a rate-limit reset header (seconds, or a Unix
     * timestamp, which some providers send).
     */
    protected function retryAfter(?Response $response): ?float
    {
        foreach (['Retry-After', 'x-sib-ratelimit-reset', 'X-RateLimit-Reset'] as $header) {
            $value = trim((string) $response?->header($header));

            if ($value === '') {
                continue;
            }

            if (is_numeric($value)) {
                $seconds = (float) $value;

                // Anything this large is a timestamp, not a delay.
                return max(0.0, $seconds > 1_000_000_000 ? $seconds - time() : $seconds);
            }

            $at = strtotime($value);

            if ($at !== false) {
                return (float) max(0, $at - time());
            }
        }

        return null;
    }

    /**
     * The response when it succeeded, else an exception with the provider's (redacted) message.
     */
    protected function ok(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        throw $this->failure($response);
    }

    protected function failure(Response $response): AudienceSyncException
    {
        $message = trim($this->errorMessage($response));

        if ($message === '') {
            $message = __('filament-audience-sync::audience-sync.errors.http', ['status' => $response->status()]);
        }

        return new AudienceSyncException(
            Redactor::clean(ucfirst($this->name).': '.$message, [$this->config['api_key'] ?? null]),
            $response->status(),
        );
    }

    /**
     * Maps neutral field keys to provider field names using the driver's "fields" config; other keys pass through.
     *
     * @param  array<string, scalar|null>  $fields
     * @return array<string, scalar>
     */
    protected function mapFields(array $fields): array
    {
        $map = (array) ($this->config['fields'] ?? []);
        $out = [];

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $out[(string) ($map[$key] ?? $key)] = $value;
        }

        return $out;
    }

    protected static function rate(mixed $part, mixed $whole): ?float
    {
        $whole = (float) $whole;

        return $whole > 0 ? round(100 * (float) $part / $whole, 1) : null;
    }

    protected static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
