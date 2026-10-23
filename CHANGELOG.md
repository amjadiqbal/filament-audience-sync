# Changelog

All notable changes to `filament-audience-sync` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-23

### Added

- Mailchimp and Brevo drivers behind an `AudienceDriver` contract, with `AudienceSync::extend()` for more providers.
- Subscribe, sync details, edit tags and unsubscribe record actions; queued bulk subscribe, sync and unsubscribe.
- Audience status column and infolist entry, read from a local `audience_sync_members` table.
- List stats and recent campaigns dashboard widgets (cached).
- Consent rules: no one who unsubscribed, bounced or was archived, in the provider or here, is subscribed again.
- Retries with `Retry-After` and exponential backoff on 429, 5xx and connection errors; API keys redacted from
  every error message.
- `audience-sync:lists` command.

[Unreleased]: https://github.com/amjadiqbal/filament-audience-sync/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/amjadiqbal/filament-audience-sync/releases/tag/v1.0.0
