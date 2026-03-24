# adheart/logging

Уніфіковане логування для PHP-проєктів на базі Monolog + Symfony Bundle:
- єдиний JSON-формат логів (`SchemaFormatterV1`);
- процесори для нормалізації повідомлень і trace-контексту;
- інтеграція з OpenTelemetry trace context;
- інвентаризація використання логерів через `logging:scan`.

## Що ви отримуєте після інтеграції

- Автоматичне застосування форматера до Monolog handlers (`setFormatter` там, де підтримується).
- Автоматичне підключення вибраних процесорів до всіх Monolog loggers.
- Однакова структура подій у всіх сервісах (поля `timestamp`, `level`, `message`, `context`, `service`, `trace`, `version`).
- Опціонально: enrichment trace-контекстом з OpenTelemetry і заголовка `cf-ray`.

## Сумісність

- PHP: `^8.2`
- Monolog: `^2.3`
- Symfony components:
  - `symfony/http-kernel`: `^5.4 || ^6.4 || ^7.0`
  - `symfony/config`: `^5.4 || ^6.4 || ^7.0`

Додатково для `logging:scan`:
- `symfony/console`
- `symfony/dependency-injection`
- `nikic/php-parser`

## Встановлення

```bash
composer require adheart/logging
```

Якщо це не Symfony Runtime з Flex, переконайтесь, що бандл зареєстрований вручну в `config/bundles.php`:

```php
<?php

return [
    // ...
    Adheart\Logging\LoggingBundle::class => ['all' => true],
];
```

## Швидкий старт (Symfony)

Створіть `config/packages/logging.yaml`:

```yaml
logging:
  processors:
    - message_normalizer
  integrations:
    - otel_trace
  formatter:
    schema_version: '1.0.0'
    service_name: 'billing-api'
    service_version: '%env(string:RELEASE_ID)%'
```

Після цього:
- на всі `monolog.logger*` буде додано процесори;
- на всі `monolog.handler*` буде встановлено `SchemaFormatterV1`;
- логи підуть у стандартизованому JSON.

## Базова перевірка після інтеграції

1. Очистіть/прогрійте контейнер:
```bash
php bin/console cache:clear
```
2. Згенеруйте тестовий лог із будь-якого місця в застосунку:
```php
$logger->info('User logged in', ['user' => ['id' => '123']]);
```
3. Перевірте raw-рядок у stdout/file-handler: має бути валідний JSON з полями `service` і `version`.

## Вбудовані alias-и

### Processors
- `message_normalizer` → `Adheart\Logging\Core\Processors\MessageNormalizerProcessor`
- `trace` → `Adheart\Logging\Core\Processors\TraceContextProcessor`

### Trace providers
- `otel` → `Adheart\Logging\Integration\OpenTelemetry\Trace\OpenTelemetryTraceContextProvider`
- `cf_ray` → `Adheart\Logging\Integration\OpenTelemetry\Trace\CfRayTraceContextProvider`

### Integrations
- `otel_trace`:
  - processors: `trace`
  - trace_providers: `otel`, `cf_ray`

## Кастомізація через alias-и

Можна підключати свої сервіси без форку пакета:

```yaml
logging:
  processors:
    - custom_processor
  integrations:
    - custom_trace
  aliases:
    processors:
      custom_processor: '@app.logging.processor.custom'
    trace_providers:
      app_provider: '@app.trace.provider'
    integrations:
      custom_trace:
        processors: ['trace']
        trace_providers: ['app_provider']
```

## Команда інвентаризації логів

Якщо встановлені dev-залежності для сканера, доступна команда:

```bash
php bin/console logging:scan --summary
```

Приклади:

```bash
php bin/console logging:scan --format=json --summary
php bin/console logging:scan --logger-name=app --severity-min=error
php bin/console logging:scan --path-prefix=src/Billing --exclude-path-prefix=src/Billing/Legacy
php bin/console logging:scan --list-loggers
```

## Детальна документація

- [Покрокова інтеграція (детально)](docs/integration-guide.md)
- [Production checklist](docs/production-checklist.md)
- [Конфіг Symfony bundle](docs/symfony-bundle-config.md)
- [Референс схеми логів v1](docs/log-schema-v1.md)
