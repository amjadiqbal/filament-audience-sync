<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentAudienceSync\Tests;

use AmjadIqbal\FilamentAudienceSync\AudienceSyncManager;
use AmjadIqbal\FilamentAudienceSync\FilamentAudienceSyncServiceProvider;
use AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\AdminPanelProvider;
use AmjadIqbal\FilamentAudienceSync\Tests\Fixtures\User;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends Orchestra
{
    // Fake keys, assembled so that no key-shaped literal sits in the repository (secret scanners would flag it).
    public const MAILCHIMP_KEY = '0123456789abcdef'.'0123456789abcdef'.'-us21';

    public const BREVO_KEY = 'xkey'.'sib-fixture0000000000000000000000000000-AbCdEfGh';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
    }

    protected function getPackageProviders($app): array
    {
        $providers = [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            PowerJoinsServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            'Filament\\QueryBuilder\\QueryBuilderServiceProvider',
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            FilamentAudienceSyncServiceProvider::class,
            AdminPanelProvider::class,
        ];

        return array_values(array_filter($providers, 'class_exists'));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('audience-sync.default', 'mailchimp');
        $app['config']->set('audience-sync.drivers.mailchimp.api_key', self::MAILCHIMP_KEY);
        $app['config']->set('audience-sync.drivers.mailchimp.list', 'aud123');
        $app['config']->set('audience-sync.drivers.brevo.api_key', self::BREVO_KEY);
        $app['config']->set('audience-sync.drivers.brevo.list', '7');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        (require __DIR__.'/../database/migrations/create_audience_sync_members_table.php.stub')->up();
    }

    /**
     * Switch the default driver (drivers are rebuilt from config).
     */
    protected function useDriver(string $driver): void
    {
        config()->set('audience-sync.default', $driver);
        app(AudienceSyncManager::class)->forgetDrivers();
    }

    protected function makeUser(string $name = 'Ada Lovelace', ?string $email = null): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email ?? strtolower(str_replace(' ', '.', $name)).'@example.com',
            'password' => 'secret-password',
        ]);
    }

    /**
     * A recorded provider response from tests/Fixtures/responses (__MAILCHIMP_KEY__ / __BREVO_KEY__ filled in).
     *
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        $json = strtr((string) file_get_contents(__DIR__."/Fixtures/responses/{$name}.json"), [
            '__MAILCHIMP_KEY__' => self::MAILCHIMP_KEY,
            '__BREVO_KEY__' => self::BREVO_KEY,
        ]);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
