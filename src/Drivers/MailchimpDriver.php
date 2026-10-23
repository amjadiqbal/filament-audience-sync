<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Drivers;

use AmjadIqbal\FilamentAudienceSync\Data\Campaign;
use AmjadIqbal\FilamentAudienceSync\Data\Contact;
use AmjadIqbal\FilamentAudienceSync\Data\ListStats;
use AmjadIqbal\FilamentAudienceSync\Data\Member;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use AmjadIqbal\FilamentAudienceSync\Exceptions\NotConfiguredException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Mailchimp Marketing API 3.0, one audience. https://mailchimp.com/developer/marketing/api/
 */
class MailchimpDriver extends HttpDriver
{
    public function supports(string $feature): bool
    {
        return in_array($feature, [self::FEATURE_TAGS, self::FEATURE_CAMPAIGNS, self::FEATURE_DOUBLE_OPT_IN], true);
    }

    protected function baseUrl(): string
    {
        $server = trim((string) ($this->config['server'] ?? ''));

        if ($server === '' && preg_match('/-([a-z]{2,4}\d{1,3})$/i', $this->apiKey(), $m) === 1) {
            $server = $m[1];
        }

        // The data centre becomes part of the host name, so only the documented shape is accepted.
        if (preg_match('/^[a-z]{2,4}\d{1,3}$/i', $server) !== 1) {
            throw new NotConfiguredException(__('filament-audience-sync::audience-sync.errors.mailchimp_server'));
        }

        return 'https://'.strtolower($server).'.api.mailchimp.com/3.0';
    }

    protected function authenticate(PendingRequest $request, string $apiKey): PendingRequest
    {
        return $request->withBasicAuth('audience-sync', $apiKey);
    }

    protected function errorMessage(Response $response): string
    {
        $detail = (string) ($response->json('detail') ?: $response->json('title') ?: '');
        $fields = collect((array) $response->json('errors', []))
            ->map(fn (mixed $e): string => is_array($e) ? trim(($e['field'] ?? '').': '.($e['message'] ?? ''), ': ') : '')
            ->filter()
            ->implode('; ');

        return trim($detail.($fields !== '' ? " ({$fields})" : ''));
    }

    public static function hash(string $email): string
    {
        return md5(strtolower(trim($email)));
    }

    protected function memberPath(string $email): string
    {
        return 'lists/'.rawurlencode($this->listId()).'/members/'.static::hash($email);
    }

    public function member(string $email): Member
    {
        $response = $this->request('get', $this->memberPath($email), ['fields' => 'id,email_address,status,tags']);

        if ($response->status() === 404) {
            return Member::absent($email);
        }

        return $this->toMember($this->ok($response)->json(), $email);
    }

    public function subscribe(Contact $contact, Member $current): Member
    {
        $status = ($this->config['double_opt_in'] ?? false) ? 'pending' : 'subscribed';
        $fields = $this->mapFields($contact->fields);

        if ($current->providerStatus === 'transactional') {
            // A transactional contact exists but never opted in to marketing: PUT changes only its status and fields.
            $body = ['email_address' => $contact->email, 'status_if_new' => $status, 'status' => $status];

            if ($fields !== []) {
                $body['merge_fields'] = $fields;
            }

            $member = $this->toMember($this->ok($this->request('put', $this->memberPath($contact->email), $body))->json(), $contact->email);

            if ($contact->tags !== []) {
                $this->tag($contact->email, $contact->tags);
            }

            return new Member($member->email, $member->status, $member->providerId, array_values(array_unique([...$member->tags, ...$contact->tags])), $member->providerStatus);
        }

        // POST only creates: if the address appeared meanwhile (maybe unsubscribed), Mailchimp answers "Member Exists"
        // instead of changing its status.
        $body = ['email_address' => $contact->email, 'status' => $status];

        if ($fields !== []) {
            $body['merge_fields'] = $fields;
        }

        if ($contact->tags !== []) {
            $body['tags'] = $contact->tags;
        }

        return $this->toMember(
            $this->ok($this->request('post', 'lists/'.rawurlencode($this->listId()).'/members', $body))->json(),
            $contact->email,
        );
    }

    public function update(Contact $contact): Member
    {
        $fields = $this->mapFields($contact->fields);

        // PATCH without "status": Mailchimp keeps the member's subscription status as it is.
        $member = $fields !== []
            ? $this->toMember($this->ok($this->request('patch', $this->memberPath($contact->email), ['merge_fields' => $fields]))->json(), $contact->email)
            : $this->member($contact->email);

        if ($member->status === Status::NotSubscribed) {
            return $member;
        }

        if ($contact->tags !== []) {
            $this->tag($contact->email, $contact->tags);

            return new Member($member->email, $member->status, $member->providerId, array_values(array_unique([...$member->tags, ...$contact->tags])), $member->providerStatus);
        }

        return $member;
    }

    public function unsubscribe(string $email): Member
    {
        $response = $this->request('patch', $this->memberPath($email), ['status' => 'unsubscribed']);

        if ($response->status() === 404) {
            return Member::absent($email);
        }

        return $this->toMember($this->ok($response)->json(), $email);
    }

    public function tag(string $email, array $add, array $remove = []): void
    {
        $tags = [
            ...array_map(fn (string $t): array => ['name' => $t, 'status' => 'active'], $add),
            ...array_map(fn (string $t): array => ['name' => $t, 'status' => 'inactive'], $remove),
        ];

        if ($tags === []) {
            return;
        }

        $response = $this->request('post', $this->memberPath($email).'/tags', ['tags' => $tags]);

        if ($response->status() === 404) {
            throw new AudienceSyncException(__('filament-audience-sync::audience-sync.errors.not_member'), 404);
        }

        $this->ok($response);
    }

    public function stats(): ListStats
    {
        $data = $this->ok($this->request('get', 'lists/'.rawurlencode($this->listId()), ['fields' => 'name,stats']))->json();
        $stats = (array) ($data['stats'] ?? []);

        // Audience stats already hold percentages (42.5), unlike campaign report summaries (0.425).
        return new ListStats(
            name: (string) ($data['name'] ?? ''),
            members: (int) ($stats['member_count'] ?? 0),
            unsubscribed: isset($stats['unsubscribe_count']) ? (int) $stats['unsubscribe_count'] : null,
            openRate: isset($stats['open_rate']) ? round((float) $stats['open_rate'], 1) : null,
            clickRate: isset($stats['click_rate']) ? round((float) $stats['click_rate'], 1) : null,
        );
    }

    public function campaigns(int $limit = 5): array
    {
        $data = $this->ok($this->request('get', 'campaigns', [
            'list_id' => $this->listId(),
            'status' => 'sent',
            'count' => max(1, min(50, $limit)),
            'sort_field' => 'send_time',
            'sort_dir' => 'DESC',
            'fields' => 'campaigns.id,campaigns.settings.subject_line,campaigns.settings.title,campaigns.send_time,campaigns.emails_sent,campaigns.report_summary',
        ]))->json();

        return array_values(array_map(function (array $c): Campaign {
            $report = (array) ($c['report_summary'] ?? []);

            return new Campaign(
                id: (string) $c['id'],
                title: (string) (($c['settings']['subject_line'] ?? '') ?: ($c['settings']['title'] ?? $c['id'])),
                sentAt: static::date($c['send_time'] ?? null),
                recipients: (int) ($c['emails_sent'] ?? 0),
                openRate: isset($report['open_rate']) ? round(100 * (float) $report['open_rate'], 1) : null,
                clickRate: isset($report['click_rate']) ? round(100 * (float) $report['click_rate'], 1) : null,
            );
        }, array_filter((array) ($data['campaigns'] ?? []), 'is_array')));
    }

    public function lists(): array
    {
        $data = $this->ok($this->request('get', 'lists', ['count' => 100, 'fields' => 'lists.id,lists.name,lists.stats.member_count']))->json();

        return collect((array) ($data['lists'] ?? []))
            ->mapWithKeys(fn (array $l): array => [(string) $l['id'] => (string) $l['name'].' ('.number_format((int) ($l['stats']['member_count'] ?? 0)).')'])
            ->all();
    }

    /**
     * @param  mixed  $data  decoded member JSON
     */
    protected function toMember(mixed $data, string $email): Member
    {
        $data = is_array($data) ? $data : [];
        $raw = (string) ($data['status'] ?? '');

        return new Member(
            email: (string) ($data['email_address'] ?? $email),
            status: match ($raw) {
                'subscribed' => Status::Subscribed,
                'pending' => Status::Pending,
                'unsubscribed' => Status::Unsubscribed,
                'cleaned' => Status::Cleaned,
                'archived' => Status::Archived,
                default => Status::NotSubscribed, // transactional, or unknown
            },
            providerId: isset($data['id']) ? (string) $data['id'] : null,
            tags: array_values(array_filter(array_map(fn (mixed $t): string => is_array($t) ? (string) ($t['name'] ?? '') : (string) $t, (array) ($data['tags'] ?? [])))),
            providerStatus: $raw !== '' ? $raw : null,
        );
    }
}
