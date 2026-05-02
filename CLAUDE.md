# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`adheart/logging` is a Symfony bundle that standardizes Monolog output across PHP services: a single JSON schema (`SchemaFormatterV1`), normalization/trace processors, OpenTelemetry trace context, and a `logging:scan` command that statically inventories logger usage in a project.

It is consumed as a library (`composer require adheart/logging`) — there is no application here, just the bundle and its tests.

## Commands

```bash
composer install                      # install deps
composer test                         # phpunit (php/tests/Unit + php/tests/Functional)
composer phpcs                        # PSR-12 lint over php/
composer psalm                        # static analysis
composer quality                      # phpcs + psalm + test (full local gate)

vendor/bin/phpunit --filter SchemaFormatterV1Test       # single test class
vendor/bin/phpunit php/tests/Unit/Core/Formatters       # single dir
vendor/bin/phpunit --testsuite Unit                     # one suite (Unit | Functional)
```

Coverage gate (replicates the `coverage-gate` CI job):

```bash
mkdir -p build/logs
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-clover build/logs/clover.xml
php php/tools/coverage-gate.php build/logs/clover.xml
```

Thresholds enforced by [coverage-gate.php](php/tools/coverage-gate.php): overall ≥ 88%, `php/src/Core/` and `php/src/DependencyInjection/` ≥ 95%. Lowering these without justification will fail CI.

CI matrix ([.github/workflows/ci.yml](.github/workflows/ci.yml)) also runs `--prefer-lowest` on every PR and a nightly compat matrix across PHP 8.2/8.3 × Symfony 6.4/7.4 — keep changes compatible with the lower bounds in `composer.json` (Monolog `^2.3 || ^3.0`, Symfony `^5.4 || ^6.4 || ^7.0`).

## Architecture

### Bundle wiring (the part most changes touch)

[LoggingBundle](php/src/LoggingBundle.php) registers three compiler passes that run **after** the user's app and Monolog bundle have built their definitions:

1. `RegisterLoggerServicesPass` — discovers `monolog.logger*` services so the scan command can later list them.
2. `ApplyLoggingProcessorsPass` — pushes every processor service id from the `logging.processor_service_ids` parameter onto every Monolog logger.
3. `ApplyLoggingFormatterPass` — calls `setFormatter()` with `logging.formatter_service_id` on every Monolog handler that supports it.

Those three parameters are populated by [AdheartLoggingExtension](php/src/DependencyInjection/AdheartLoggingExtension.php) from the user's `logging:` YAML config. The extension resolves a layered alias system:

- **Built-in aliases** (hardcoded in the extension): processors `message_normalizer`, `trace`; trace providers `otel`, `cf_ray`; integration `otel_trace`.
- **User aliases** under `logging.aliases.{processors,trace_providers,integrations}` — merged on top of built-ins.
- A value starting with `@` is treated as a raw service id; otherwise the extension first looks it up in the alias map, then falls back to treating it as a service id.

`integrations` are bundles of (processors + trace_providers) — selecting `otel_trace` adds the `trace` processor and registers the `otel` + `cf_ray` providers as `$providers` on `TraceContextProcessor`. **Unknown integration aliases throw at config time; unknown processor service ids fail at container compile time** (fail-fast is intentional — see [docs/symfony-bundle-config.md](docs/symfony-bundle-config.md)).

### Runtime path

A log call flows: user code → Monolog logger → registered processors (including `MessageNormalizerProcessor` and `TraceContextProcessor` if selected) → handler → `SchemaFormatterV1` → NDJSON output. The schema (top-level fields `timestamp`, `level`, `message`, `service`, `context`, `trace`, `version`) is the contract — see [docs/log-schema-v1.md](docs/log-schema-v1.md). Changing field shapes is a breaking schema change.

`TraceContextProcessor` accepts an ordered list of `TraceContextProviderInterface`; the first provider that returns context wins. `OpenTelemetryTraceContextProvider` reads the active OTel span; `CfRayTraceContextProvider` falls back to the `cf-ray` request header.

### Inventory / scan command (optional)

`Adheart\Logging\Command\ScanCommand` (alias `logging:scan`) is only registered when `symfony/console`, `symfony/dependency-injection`, and `nikic/php-parser` are all available — see `AdheartLoggingExtension::canRegisterScanCommand()`. The pipeline is:

`LogUsageScanner` walks `%kernel.project_dir%` → `LogUsageExtractor` parses each PHP file with `nikic/php-parser` and runs `UsageCollectingVisitor` to detect `LoggerInterface`/Monolog calls → results become `LogUsage` records → `LogUsageFilterSorter` applies CLI filters (`--logger-name`, `--severity-min`, `--path-prefix`, `--exclude-path-prefix`) → `LogUsageRenderer` emits text/JSON. `LoggerCatalogProvider` is what surfaces `--list-loggers` (populated by `RegisterLoggerServicesPass`).

If you change the visitor or extractor, also update fixtures under `php/tests/Unit/Inventory/` — the parser-based tests are the only safety net.

## Conventions worth knowing

- PHP namespace `Adheart\Logging\` maps to `php/src/`; tests live in `Tests\` → `php/tests/` and are split into `Unit/` and `Functional/` test suites.
- PSR-12 enforced by [phpcs.xml](phpcs.xml) over the whole `php/` tree.
- Psalm runs at default level via [psalm.xml](psalm.xml); the extension carries detailed `@param` array shapes for the config tree — keep them in sync with [Configuration.php](php/src/DependencyInjection/Configuration.php) when adding nodes.
- All public classes are `final` and use `declare(strict_types=1)` + `#[\Override]` on inherited methods. Match the style.
- README and docs are written in Ukrainian; keep new user-facing docs in the same language unless the user asks otherwise.
