<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Commands;

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use AmjadIqbal\FilamentAudienceSync\Exceptions\AudienceSyncException;
use Illuminate\Console\Command;

/**
 * Prints the audiences or lists the API key can see, to find the id for config('audience-sync.drivers.*.list').
 */
class ListsCommand extends Command
{
    protected $signature = 'audience-sync:lists {--driver= : mailchimp, brevo or a registered driver (default: the configured one)}';

    protected $description = 'List the audiences or lists your email-marketing API key can see, with their ids';

    public function handle(AudienceSyncManager $sync): int
    {
        try {
            $driver = $sync->driver($this->option('driver') ?: null);
            $lists = $driver->lists();
        } catch (AudienceSyncException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($lists === []) {
            $this->components->warn(__('filament-audience-sync::audience-sync.command.none'));

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name (members)'], collect($lists)->map(fn (string $name, string $id): array => [$id, $name])->values()->all());

        return self::SUCCESS;
    }
}
