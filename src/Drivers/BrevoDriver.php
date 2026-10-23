<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Drivers;

use AmjadIqbal\FilamentAudienceSync\Data\Campaign;
use AmjadIqbal\FilamentAudienceSync\Data\Contact;
use AmjadIqbal\FilamentAudienceSync\Data\ListStats;
use AmjadIqbal\FilamentAudienceSync\Data\Member;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Brevo API v3, one contact list. https://developers.brevo.com/reference
 * Brevo has no contact tags (a model's audienceTags() are not sent), and double opt-in needs a Brevo template, so
 * neither is supported here. Brevo reports no bounced or archived state: only blocklisted and list-unsubscribed.
 */
class BrevoDriver extends HttpDriver
{
    public function supports(string $feature): bool
    {
        return $feature === self::FEATURE_CAMPAIGNS;
    }

    protected function baseUrl(): string
    {
        return 'https://api.brevo.com/v3';
    }

    protected function authenticate(PendingRequest $request, string $apiKey): PendingRequest
    {
        return $request->withHeaders(['api-key' => $apiKey]);
    }

    protected function errorMessage(Response $response): string
    {
        return (string) ($response->json('message') ?: $response->json('code') ?: '');
    }

    protected function list(): int
    {
        return (int) $this->listId();
    }

    protected function contactPath(string $email): string
    {
        return 'contacts/'.rawurlencode(strtolower(trim($email)));
    }

    public function member(string $email): Member
    {
        $response = $this->request('get', $this->contactPath($email));

        if ($response->status() === 404) {
            return Member::absent($email);
        }

        $data = (array) $this->ok($response)->json();
        $list = $this->list();
        $in = fn (string $key): bool => in_array($list, array_map('intval', (array) ($data[$key] ?? [])), true);

        $status = match (true) {
            (bool) ($data['emailBlacklisted'] ?? false) => Status::Unsubscribed,
            $in('listUnsubscribed') => Status::Unsubscribed,
            $in('listIds') => Status::Subscribed,
            default => Status::NotSubscribed,
        };

        return new Member(
            email: (string) ($data['email'] ?? $email),
            status: $status,
            providerId: isset($data['id']) ? (string) $data['id'] : null,
            providerStatus: $status->value,
        );
    }

    public function subscribe(Contact $contact, Member $current): Member
    {
        // updateEnabled adds an existing contact to the list. Brevo never lifts a blocklisting unless asked to
        // (emailBlacklisted: false), which this driver never sends.
        $body = ['email' => strtolower(trim($contact->email)), 'listIds' => [$this->list()], 'updateEnabled' => true];
        $attributes = $this->mapFields($contact->fields);

        if ($attributes !== []) {
            $body['attributes'] = $attributes;
        }

        $data = (array) $this->ok($this->request('post', 'contacts', $body))->json();

        return new Member($contact->email, Status::Subscribed, isset($data['id']) ? (string) $data['id'] : $current->providerId, [], Status::Subscribed->value);
    }

    public function update(Contact $contact): Member
    {
        $attributes = $this->mapFields($contact->fields);

        if ($attributes !== []) {
            $response = $this->request('put', $this->contactPath($contact->email), ['attributes' => $attributes]);

            if ($response->status() === 404) {
                return Member::absent($contact->email);
            }

            $this->ok($response);
        }

        return $this->member($contact->email);
    }

    public function unsubscribe(string $email): Member
    {
        $body = ($this->config['unsubscribe'] ?? 'blocklist') === 'unlink'
            ? ['unlinkListIds' => [$this->list()]]
            : ['emailBlacklisted' => true];

        $response = $this->request('put', $this->contactPath($email), $body);

        if ($response->status() === 404) {
            return Member::absent($email);
        }

        $this->ok($response);

        return new Member($email, Status::Unsubscribed, null, [], Status::Unsubscribed->value);
    }

    public function tag(string $email, array $add, array $remove = []): void
    {
        throw new AudienceSyncException(__('filament-audience-sync::audience-sync.errors.no_tags', ['driver' => $this->name]));
    }

    public function stats(): ListStats
    {
        $data = (array) $this->ok($this->request('get', 'contacts/lists/'.$this->list()))->json();
        $campaigns = $this->campaigns(10);
        $avg = function (string $key) use ($campaigns): ?float {
            $rates = array_values(array_filter(array_map(fn (Campaign $c): ?float => $c->{$key}, $campaigns), fn (?float $r): bool => $r !== null));

            return $rates === [] ? null : round(array_sum($rates) / count($rates), 1);
        };

        return new ListStats(
            name: (string) ($data['name'] ?? ''),
            members: (int) ($data['uniqueSubscribers'] ?? $data['totalSubscribers'] ?? 0),
            unsubscribed: isset($data['totalBlacklisted']) ? (int) $data['totalBlacklisted'] : null,
            openRate: $avg('openRate'),
            clickRate: $avg('clickRate'),
            rateCampaigns: count($campaigns),
            unsubscribedIsBlocklist: true,
        );
    }

    public function campaigns(int $limit = 5): array
    {
        $data = (array) $this->ok($this->request('get', 'emailCampaigns', [
            'status' => 'sent',
            // Brevo cannot filter campaigns by list: the latest 100 sent campaigns are read and filtered here.
            'limit' => 100,
            'sort' => 'desc',
            'statistics' => 'globalStats',
            'excludeHtmlContent' => 'true',
        ]))->json();

        $list = $this->list();

        return collect((array) ($data['campaigns'] ?? []))
            ->filter(fn (mixed $c): bool => is_array($c) && in_array($list, array_map('intval', (array) ($c['recipients']['lists'] ?? [])), true))
            ->take(max(1, $limit))
            ->map(function (array $c): Campaign {
                $s = (array) ($c['statistics']['globalStats'] ?? []);
                $delivered = $s['delivered'] ?? null;

                return new Campaign(
                    id: (string) $c['id'],
                    title: (string) (($c['subject'] ?? '') ?: ($c['name'] ?? $c['id'])),
                    sentAt: static::date($c['sentDate'] ?? null),
                    recipients: (int) ($s['sent'] ?? $delivered ?? 0),
                    openRate: isset($s['uniqueViews']) ? static::rate($s['uniqueViews'], $delivered) : null,
                    clickRate: isset($s['uniqueClicks']) ? static::rate($s['uniqueClicks'], $delivered) : null,
                );
            })
            ->values()
            ->all();
    }

    public function lists(): array
    {
        $data = (array) $this->ok($this->request('get', 'contacts/lists', ['limit' => 50, 'offset' => 0]))->json();

        return collect((array) ($data['lists'] ?? []))
            ->mapWithKeys(fn (array $l): array => [(string) $l['id'] => (string) $l['name'].' ('.number_format((int) ($l['uniqueSubscribers'] ?? $l['totalSubscribers'] ?? 0)).')'])
            ->all();
    }
}
