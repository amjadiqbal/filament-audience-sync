<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync;

use AmjadIqbal\FilamentAudienceSync\Contracts\AudienceDriver;
use AmjadIqbal\FilamentAudienceSync\Contracts\Subscribable;
use AmjadIqbal\FilamentAudienceSync\Data\Campaign;
use AmjadIqbal\FilamentAudienceSync\Data\Contact;
use AmjadIqbal\FilamentAudienceSync\Data\ListStats;
use AmjadIqbal\FilamentAudienceSync\Data\Member;
use AmjadIqbal\FilamentAudienceSync\Data\SyncResult;
use AmjadIqbal\FilamentAudienceSync\Drivers\BrevoDriver;
use AmjadIqbal\FilamentAudienceSync\Drivers\MailchimpDriver;
use AmjadIqbal\FilamentAudienceSync\Enums\Outcome;
use AmjadIqbal\FilamentAudienceSync\Enums\Status;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use AmjadIqbal\FilamentAudienceSync\Exceptions\NotConfiguredException;
use AmjadIqbal\FilamentAudienceSync\Exceptions\RateLimitedException;
use AmjadIqbal\FilamentAudienceSync\Jobs\SyncAudienceChunk;
use AmjadIqbal\FilamentAudienceSync\Models\SyncedMember;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Drivers plus the rules every driver shares:
 *  - someone who unsubscribed (in the provider, or here, recorded on their status row) is never subscribed again;
 *  - "update" and "tag" only touch existing members and never change a subscription status;
 *  - each operation stores the result on the record's status row, so the panel reads status without an API call.
 *
 * @method AudienceDriver driver(?string $driver = null)
 */
class AudienceSyncManager extends Manager
{
    public const OPERATIONS = ['subscribe', 'update', 'unsubscribe', 'refresh'];

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('audience-sync.default', 'mailchimp');
    }

    /**
     * @return array<string, mixed>
     */
    public function driverConfig(string $driver): array
    {
        return (array) $this->config->get("audience-sync.drivers.{$driver}", []);
    }

    /**
     * The configured list id, or '' when unset (no exception: used to scope relations).
     */
    public function configuredList(?string $driver = null): string
    {
        return trim((string) ($this->driverConfig($driver ?? $this->getDefaultDriver())['list'] ?? ''));
    }

    protected function createMailchimpDriver(): AudienceDriver
    {
        return new MailchimpDriver('mailchimp', $this->driverConfig('mailchimp'));
    }

    protected function createBrevoDriver(): AudienceDriver
    {
        return new BrevoDriver('brevo', $this->driverConfig('brevo'));
    }

    /**
     * Subscribe the record, or, if it is already a member, update its fields and tags. Skips anyone who unsubscribed.
     */
    public function subscribe(Model $record, ?string $driver = null): SyncResult
    {
        return $this->run($record, $driver, function (AudienceDriver $d, string $email, ?SyncedMember $local) use ($record): array {
            if ($this->unsubscribedHere($d, $email, $local)) {
                return [Outcome::SkippedUnsubscribed, null];
            }

            $member = $d->member($email);

            if ($member->status->blocksSubscribe()) {
                return [Outcome::SkippedUnsubscribed, $member];
            }

            $contact = $this->contact($record, $email);

            if ($member->status === Status::Subscribed || $member->status === Status::Pending) {
                return [Outcome::Updated, $d->update($contact)];
            }

            $member = $d->subscribe($contact, $member);

            return [$member->status === Status::Pending ? Outcome::Pending : Outcome::Subscribed, $member];
        });
    }

    /**
     * Push fields and tags to an existing, subscribed or pending member, and refresh its status. Never subscribes.
     */
    public function update(Model $record, ?string $driver = null): SyncResult
    {
        return $this->run($record, $driver, function (AudienceDriver $d, string $email) use ($record): array {
            $member = $d->member($email);

            if ($member->status->blocksSubscribe()) {
                return [Outcome::SkippedUnsubscribed, $member];
            }

            if ($member->status === Status::NotSubscribed) {
                return [Outcome::SkippedNotMember, $member];
            }

            return [Outcome::Updated, $d->update($this->contact($record, $email))];
        });
    }

    /**
     * Unsubscribe in the provider and record the choice here, even if the provider never knew the address.
     */
    public function unsubscribe(Model $record, ?string $driver = null): SyncResult
    {
        return $this->run($record, $driver, function (AudienceDriver $d, string $email): array {
            $member = $d->unsubscribe($email);

            return [Outcome::Unsubscribed, new Member($member->email, Status::Unsubscribed, $member->providerId, $member->tags, $member->providerStatus)];
        });
    }

    /**
     * Read the status from the provider and store it. Sends nothing.
     */
    public function refresh(Model $record, ?string $driver = null): SyncResult
    {
        return $this->run($record, $driver, fn (AudienceDriver $d, string $email): array => [Outcome::Updated, $d->member($email)]);
    }

    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function tag(Model $record, array $add, array $remove = [], ?string $driver = null): SyncResult
    {
        return $this->run($record, $driver, function (AudienceDriver $d, string $email) use ($add, $remove): array {
            if (! $d->supports(AudienceDriver::FEATURE_TAGS)) {
                throw new AudienceSyncException(__('filament-audience-sync::audience-sync.errors.no_tags', ['driver' => $d->name()]));
            }

            $member = $d->member($email);

            if ($member->status === Status::NotSubscribed) {
                return [Outcome::SkippedNotMember, $member];
            }

            $d->tag($email, array_values(array_filter($add)), array_values(array_filter($remove)));

            return [Outcome::Updated, $d->member($email)];
        });
    }

    /**
     * Queue an operation for many records, one job per chunk (config audience-sync.queue.chunk).
     *
     * @param  iterable<Model>  $records
     * @return int the number of records queued
     */
    public function queue(iterable $records, string $operation, ?string $driver = null): int
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException("Unknown audience operation [{$operation}].");
        }

        $chunk = max(1, (int) $this->config->get('audience-sync.queue.chunk', 50));
        $byModel = [];

        foreach ($records as $record) {
            $byModel[$record::class][] = $record->getKey();
        }

        $count = 0;

        foreach ($byModel as $model => $ids) {
            foreach (array_chunk($ids, $chunk) as $part) {
                SyncAudienceChunk::dispatch($model, $part, $operation, $driver)
                    ->onConnection($this->config->get('audience-sync.queue.connection'))
                    ->onQueue($this->config->get('audience-sync.queue.name'));
                $count += count($part);
            }
        }

        return $count;
    }

    public function stats(?string $driver = null): ListStats
    {
        $d = $this->driver($driver);

        return $this->cached($d, 'stats', fn (): ListStats => $d->stats());
    }

    /**
     * @return list<Campaign>
     */
    public function campaigns(int $limit = 5, ?string $driver = null): array
    {
        $d = $this->driver($driver);

        return $this->cached($d, "campaigns:{$limit}", fn (): array => $d->campaigns($limit));
    }

    public function membership(Model $record, ?string $driver = null): ?SyncedMember
    {
        $driver ??= $this->getDefaultDriver();

        /** @var SyncedMember|null */
        return SyncedMember::query()
            ->where('subscribable_type', $record->getMorphClass())
            ->where('subscribable_id', $record->getKey())
            ->where('driver', $driver)
            ->where('list_id', $this->configuredList($driver))
            ->first();
    }

    /**
     * @param  Closure(AudienceDriver, string, ?SyncedMember): array{0: Outcome, 1: ?Member}  $operation
     */
    protected function run(Model $record, ?string $driver, Closure $operation): SyncResult
    {
        if (! $record instanceof Subscribable) {
            throw new InvalidArgumentException($record::class.' must implement '.Subscribable::class.'.');
        }

        $email = $record->audienceEmail();

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return new SyncResult(Outcome::SkippedNoEmail);
        }

        $d = $this->driver($driver);
        $local = $this->membership($record, $d->name());

        try {
            [$outcome, $member] = $operation($d, $email, $local);
        } catch (RateLimitedException $e) {
            throw $e;
        } catch (NotConfiguredException $e) {
            return new SyncResult(Outcome::Failed, $local?->status, $e->getMessage());
        } catch (AudienceSyncException $e) {
            $this->store($record, $d, $email, $local, null, $e->getMessage());

            return new SyncResult(Outcome::Failed, $local?->status, $e->getMessage());
        }

        $row = $this->store($record, $d, $email, $local, $member, null);

        return new SyncResult($outcome, $row->status);
    }

    protected function store(Model $record, AudienceDriver $d, string $email, ?SyncedMember $local, ?Member $member, ?string $error): SyncedMember
    {
        $row = $local ?? new SyncedMember([
            'subscribable_type' => $record->getMorphClass(),
            'subscribable_id' => $record->getKey(),
            'driver' => $d->name(),
            'list_id' => $d->listId(),
            'status' => Status::NotSubscribed,
        ]);

        $row->email = strtolower($email);
        $row->last_error = $error;

        if ($member !== null) {
            $status = $member->status;

            // An unsubscribe recorded here stays until the provider itself reports the person subscribed again
            // (they re-joined through the provider's own form).
            if ($row->status === Status::Unsubscribed && ! in_array($status, [Status::Subscribed, Status::Pending], true)) {
                $status = Status::Unsubscribed;
            }

            if ($status === Status::Unsubscribed && $row->status !== Status::Unsubscribed) {
                $row->unsubscribed_at = now();
            }

            $row->status = $status;
            $row->tags = $member->tags !== [] ? $member->tags : $row->tags;
            $row->provider_id = $member->providerId ?? $row->provider_id;
            $row->synced_at = now();
        }

        $row->save();

        return $row;
    }

    protected function unsubscribedHere(AudienceDriver $d, string $email, ?SyncedMember $local): bool
    {
        if ($local?->status === Status::Unsubscribed) {
            return true;
        }

        return SyncedMember::query()
            ->where('driver', $d->name())
            ->where('list_id', $d->listId())
            ->where('email', strtolower($email))
            ->where('status', Status::Unsubscribed->value)
            ->exists();
    }

    protected function contact(Model $record, string $email): Contact
    {
        if (! $record instanceof Subscribable) {
            throw new InvalidArgumentException($record::class.' must implement '.Subscribable::class.'.');
        }

        return new Contact($email, $record->audienceFields(), array_values(array_unique(array_filter($record->audienceTags()))));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function cached(AudienceDriver $d, string $what, Closure $callback): mixed
    {
        $seconds = (int) $this->config->get('audience-sync.cache_seconds', 300);

        if ($seconds <= 0) {
            return $callback();
        }

        return Cache::remember("audience-sync:{$d->name()}:{$d->listId()}:{$what}", $seconds, $callback);
    }
}
