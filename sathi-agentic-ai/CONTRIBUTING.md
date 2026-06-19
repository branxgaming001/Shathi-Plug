# Contributing to Saathi Agentic AI

By **RAI Labs Pvt. Ltd.** — https://railabs.in · License: GPL-2.0-or-later

This guide helps a human developer **or an AI assistant** safely extend the plugin.

## Architecture at a glance
PHP 8.1+, Composer PSR-4 (`RaiLabs\Sathi\` → `src/`). Domain-structured:

| Folder | Responsibility |
|---|---|
| `Core/` | Bootstrap (`Plugin`), `Settings`, activation, DB `Schema`, data DTOs |
| `Providers/` | AI providers behind `Contracts\ProviderInterface` + `Factory` + `ProviderCatalog` |
| `Rest/` | WP REST API controllers (`RestServer` registers them) |
| `Chat/` `Agent/` | Conversation + agentic tool-calling loop |
| `Knowledge/` | Site crawler + vector store (`VectorStoreInterface`) |
| `Memory/` `Personas/` | Persistent memory + persona/prompt composition |
| `Commerce/` `Abilities/` | WooCommerce search + WP/Woo "abilities" (tools) |
| `License/` | `LicenseManager` — signed license verification + entitlement gating |
| `Support/` | Helpers, Logger, GDPR, UsageTracker, ContentModerator, Mascots |

## Golden rules
1. **Never break activation.** Guard new code; fail soft.
2. **Security:** sanitize input, escape output, check capabilities, verify nonces on every write. Always use prepared statements (`$wpdb->prepare`).
3. **Published-only data:** the crawler/knowledge layer must ignore drafts, private, and trashed content.
4. **Extend via hooks**, don't fork core — see `HOOKS.md`.
5. **Keep files small + typed**; one class per file; constructor wires dependencies, `register()` wires WP hooks (never hooks in constructors).

## Common tasks
**Add an AI provider:** implement `Providers\Contracts\ProviderInterface`, register it in `Providers\Factory` + `ProviderCatalog`. No other file should need changes.

**Add a REST endpoint:** create a controller in `Rest/`, register its routes, and add it to `Rest\RestServer`.

**Add a tool/ability:** add to `Abilities\WordPressAbilities` or `WooCommerceAbilities` and expose via the `sathi_chat_tools` filter.

**Gate a premium feature:** check `LicenseManager::can('feature')`; for server-validated value use `LicenseManager::premium_directive()`. Never trust client-only flags.

## Quality gates (run before committing)
```bash
composer install
composer lint      # PHPCS (WordPress standard)
composer analyse   # PHPStan (see phpstan.neon)
composer test      # PHPUnit
```
CI runs all three on PHP 8.1 / 8.2 / 8.4 (see `.github/workflows/ci.yml`).

## Versioning
Bump the version in **three** places together: the `Version:` header and `SATHI_VERSION` in `sathi-agentic-ai.php`, and `version` in `composer.json`. Use semver.

## Roadmap notes
- Roll out `declare(strict_types=1)` file-by-file **after** PHPStan is clean (it changes runtime type coercion — test each module).
- Move schema changes to a versioned migration runner in `Core/Database`.
