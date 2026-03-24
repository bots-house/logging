# Інтеграція `adheart/logging` в інші проєкти

Документ описує практичну інтеграцію пакета у Symfony/Monolog-проєкти:
- мінімальний production-ready сценарій;
- розширення через власні процесори та trace providers;
- non-Symfony підключення через пряме створення Monolog stack;
- валідація і типові проблеми.

## 1. Передумови

- PHP `8.2+`
- Monolog `2.3+` (включно з `3.x`)
- Symfony з компонентами `http-kernel` і `config`

Опційно для CLI-сканера:
- `symfony/console`
- `symfony/dependency-injection`
- `nikic/php-parser`

## 2. Встановлення

```bash
composer require adheart/logging
```

Для проєктів без auto-discovery бандлів:

```php
<?php
// config/bundles.php

return [
    // ...
    Adheart\Logging\LoggingBundle::class => ['all' => true],
];
```

## 3. Мінімальна інтеграція у Symfony

### 3.1. Конфігурація пакета

Створіть `config/packages/logging.yaml`:

```yaml
logging:
  processors:
    - message_normalizer
  formatter:
    schema_version: '1.0.0'
    service_name: 'orders-api'
    service_version: '%env(string:RELEASE_ID)%'
```

Що відбувається при компіляції контейнера:
- `message_normalizer` додається до всіх `monolog.logger*` як `pushProcessor(...)`;
- `SchemaFormatterV1` застосовується до всіх `monolog.handler*`, які мають `setFormatter()`.

### 3.2. Додавання trace-контексту (OTel + Cloudflare)

```yaml
logging:
  processors:
    - message_normalizer
  integrations:
    - otel_trace
  formatter:
    schema_version: '1.0.0'
    service_name: 'orders-api'
    service_version: '%env(string:RELEASE_ID)%'
```

`otel_trace` вмикає:
- процесор `trace`;
- провайдери `otel` і `cf_ray`.

В результаті в `trace` можуть зʼявитися:
- `trace_id`
- `span_id`
- `sampled`
- `traceparent`
- `cf_ray`

## 4. Що роблять вбудовані процесори

## `message_normalizer`

Якщо повідомлення починається з JSON (`{` або `[`):
- оригінал переноситься в `context.message_json`;
- `message` стає `[json moved to context.message_json]`.

Це дозволяє уникати шуму у `message`, але не втрачати payload.

## `trace`

Процесор збирає дані від усіх зареєстрованих trace providers і пише їх в `extra.trace`, не перезаписуючи уже наявні ключі.

## 5. Розширення через власні alias-и

Якщо потрібні ваші процесори/провайдери/інтеграції:

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

Правила:
- значення можна задавати як alias або напряму service id;
- префікс `@` опційний (буде нормалізований);
- невідомий alias інтеграції дає помилку конфігурації одразу (fail-fast).

## 6. Приклад вихідного JSON

Схема, яку формує `SchemaFormatterV1`:

```json
{
  "timestamp": "2026-03-11T13:17:19.308Z",
  "level": { "level": 400, "severity": "ERROR" },
  "message": "Payment failed",
  "context": {
    "order_id": "ord_123",
    "extra": {}
  },
  "service": {
    "name": "orders-api",
    "version": "2026.03.24",
    "channel": "app"
  },
  "trace": {
    "trace_id": "dff3d9dca1dd07c965cfd1b68d780c7a",
    "span_id": "0f6ea59d1286a584",
    "sampled": "01",
    "traceparent": "00-dff3d9dca1dd07c965cfd1b68d780c7a-0f6ea59d1286a584-01"
  },
  "version": "1.0.0"
}
```

## 7. Використання у non-Symfony проєкті (чистий Monolog)

Цей пакет оптимізований під Symfony Bundle, але core-компоненти можна підключити вручну.

```php
<?php

use Adheart\Logging\Core\Formatters\SchemaFormatterV1;
use Adheart\Logging\Core\Processors\MessageNormalizerProcessor;
use Adheart\Logging\Core\Processors\TraceContextProcessor;
use Adheart\Logging\Integration\OpenTelemetry\Trace\OpenTelemetryTraceContextProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$handler = new StreamHandler('php://stdout');
$handler->setFormatter(new SchemaFormatterV1('1.0.0', 'orders-api', '2026.03.24'));

$logger = new Logger('app');
$logger->pushHandler($handler);

$logger->pushProcessor(new MessageNormalizerProcessor());
$logger->pushProcessor(new TraceContextProcessor([
    new OpenTelemetryTraceContextProvider(),
]));

$logger->info('Order paid', ['order_id' => 'ord_123']);
```

Примітка: `CfRayTraceContextProvider` залежить від `RequestStack`, тому без Symfony HTTP-контексту його краще не використовувати.

## 8. `logging:scan` для ревʼю покриття логування

Команда аналізує використання PSR-3 логерів у коді.

Базовий запуск:

```bash
php bin/console logging:scan --summary
```

Корисні параметри:
- `--paths=src,packages/Foo`
- `--path-prefix=src/Billing`
- `--exclude-path-prefix=src/Legacy`
- `--domain-context=Billing,User`
- `--logger-name=app`
- `--severity-min=error`
- `--only-severity=warning,error`
- `--strict-severity`
- `--format=json`
- `--list-loggers`

## 9. Рекомендований rollout-план для нового сервісу

1. Підключити пакет + мінімальний `logging.yaml`.
2. Вивести логи в stdout (контейнерний runtime).
3. Перевірити 5-10 реальних записів у staging (структура + `service.name/version`).
4. Увімкнути `otel_trace` і перевірити кореляцію `trace_id` з вашим tracing backend.
5. Прогнати `logging:scan --summary` і закрити критичні прогалини в логуванні.

## 10. Типові проблеми та рішення

## `Unknown logging integration alias "..."`

Причина:
- в `logging.integrations` передано alias, якого немає у builtin або `aliases.integrations`.

Рішення:
- виправити назву alias або додати його в `aliases.integrations`.

## `Configured logging processor "... is not a registered service."`

Причина:
- alias вказує на service id, який не існує в контейнері.

Рішення:
- перевірити реєстрацію сервісу і правильність service id.

## Форматер не застосувався до конкретного handler

Причина:
- клас handler не має `setFormatter()`.

Рішення:
- використати Monolog handler, який підтримує форматери, або загорнути кастомним handler-адаптером.

## Немає trace-полів у логах

Причина:
- не активовано `otel_trace`;
- відсутній активний span в OpenTelemetry context;
- немає `cf-ray` header для поточного запиту.

Рішення:
- перевірити конфіг `logging.integrations`;
- перевірити, що instrumentation створює активний span у момент логування.

