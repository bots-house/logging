# Production Checklist для `adheart/logging`

Чекліст для безпечного rollout пакета в новий або існуючий сервіс.

## 1. Перед інтеграцією

- [ ] Сервіс працює на PHP `8.2+`.
- [ ] У проєкті використовується Monolog `^2.3`.
- [ ] Для Symfony-проєкту підтверджено сумісну версію `http-kernel` і `config` (`^5.4 || ^6.4 || ^7.0`).
- [ ] Узгоджено `service_name` (стабільна технічна назва сервісу для всіх оточень).
- [ ] Узгоджено джерело `service_version` (наприклад, `%env(string:RELEASE_ID)%`).

## 2. Базова інтеграція

- [ ] Встановлено пакет: `composer require adheart/logging`.
- [ ] Бандл підключений (auto-discovery або вручну в `config/bundles.php`).
- [ ] Створено `config/packages/logging.yaml`.
- [ ] У конфігу задано `formatter.schema_version` (зазвичай `1.0.0`).
- [ ] У конфігу задано `formatter.service_name`.
- [ ] У конфігу задано `formatter.service_version`.
- [ ] У `processors` увімкнено `message_normalizer` (рекомендовано).

## 3. Trace-контекст (опційно, але рекомендовано)

- [ ] Увімкнено `integrations: [otel_trace]`, якщо сервіс використовує tracing.
- [ ] Перевірено, що OpenTelemetry instrumentation створює активний span.
- [ ] Для HTTP-сервісу перевірено прокидування `cf-ray` (якщо трафік через Cloudflare).

## 4. Перевірки в локальному/CI середовищі

- [ ] `php bin/console cache:clear` проходить без помилок DI-конфігурації.
- [ ] Немає помилок виду `Unknown logging integration alias`.
- [ ] Немає помилок виду `Configured logging processor "... is not a registered service."`.
- [ ] Smoke-тест логування виконується: створено мінімум 1 тестовий запис.
- [ ] Лог є валідним JSON (один рядок на подію, UTF-8).

## 5. Перевірки в Staging

- [ ] Згенеровано тестові `info`, `warning`, `error` події.
- [ ] У кожній події присутні поля:
  - [ ] `timestamp`
  - [ ] `level.level`
  - [ ] `level.severity`
  - [ ] `message`
  - [ ] `context`
  - [ ] `service.name`
  - [ ] `version`
- [ ] `service.name` відповідає узгодженому значенню.
- [ ] `service.version` відповідає поточному релізу.
- [ ] Для трасованих запитів наявні `trace.trace_id` і `trace.span_id`.
- [ ] Для JSON-повідомлень перевірено роботу `message_normalizer`:
  - [ ] `message = [json moved to context.message_json]`
  - [ ] оригінальний payload у `context.message_json`
- [ ] Volume логів не виріс аномально після увімкнення форматера/процесорів.

## 6. Перевірки ingest/observability

- [ ] Логи реально доходять до цільового сховища (Loki/ELK/інше).
- [ ] Парсинг JSON на ingest-шарі працює без помилок.
- [ ] Налаштовані або перевірені ключові дашборди за `service.name`.
- [ ] Працюють фільтри за `level.severity`.
- [ ] Працює кореляція логів із trace по `trace_id`.
- [ ] Для контейнерного деплою перевірено Docker labels (якщо використовуються в інфраструктурі):
  - [ ] `vector.log.collect=true`
  - [ ] `vector.log.schema=app`

## 7. Перевірки `logging:scan` (рекомендовано)

- [ ] Команда доступна: `php bin/console logging:scan --list-loggers`.
- [ ] Базовий звіт: `php bin/console logging:scan --summary`.
- [ ] Звіт по критичних рівнях: `php bin/console logging:scan --severity-min=error --summary`.
- [ ] Перевірено ключові домени через `--path-prefix` / `--domain-context`.
- [ ] Виявлені прогалини в логуванні заведені в backlog.

## 8. Go-live критерії

- [ ] Немає compile/runtime помилок, повʼязаних з інтеграцією логування.
- [ ] У проді підтверджено мінімум 10 реальних подій зі стабільною схемою.
- [ ] Немає деградації latency/CPU через логування.
- [ ] On-call/чергова команда знає, де дивитись нові поля (`service`, `trace`, `version`).
- [ ] Документація сервісу оновлена посиланням на цей checklist.

## 9. План відкату

- [ ] Підготовлено rollback commit або feature toggle для `logging.yaml`.
- [ ] Відомо, як швидко вимкнути `integrations: [otel_trace]` у разі проблем.
- [ ] Відомо, як повернути попередній formatter handlers (через rollback конфігу/релізу).
- [ ] Канал ескалації визначено (хто приймає рішення про rollback).

