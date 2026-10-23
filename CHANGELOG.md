# Changelog

All notable changes to `filament-audience-sync` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.1] - 2026-10-23

### Fixed

- `max_wait_seconds` now caps the total time slept across the retries of one call, not each single wait.
- Rate-limit reset headers sent as a Unix timestamp, and `Retry-After` sent as an HTTP date, are read as a wait
  instead of giving up at once; a released job waits at most an hour.

### Changed

- The stats widget says where its numbers come from: "Audience average" (Mailchimp) or "Last N campaigns to this
  list" (Brevo), and "Blocklisted contacts in the list" for Brevo's unsubscribed count.
- Brevo's campaigns widget reads the latest 100 sent campaigns (was 50) before keeping those sent to your list.
- README: precise consent, status, retry and Brevo behaviour.

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

[Unreleased]: https://github.com/amjadiqbal/filament-audience-sync/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/amjadiqbal/filament-audience-sync/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/amjadiqbal/filament-audience-sync/releases/tag/v1.0.0
