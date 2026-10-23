# План развития SSO PHP

## Правила ведения

- Это единственный активный backlog; завершённые исторические этапы здесь не хранятся.
- Порядок внутри приоритета задаёт рекомендуемую последовательность.
- Пункт закрывается после кода, миграции при необходимости, автоматических тестов и обновления документации.
- Новое внешнее поведение фиксируется в HTTP/API-контракте `ARCHITECTURE.md` и contract/integration tests.

## P2 — архитектура и поддерживаемость

### 1. Сделать OpenAPI действительно машиночитаемым контрактом

- Дополнить оставшиеся операции полным применимым набором `400/404/409/500`; для permission-protected administrative operations набор `401/403` уже полный, а `429` синхронизирован со всеми `rateLimitedRoutes` — оба правила закреплены статической проверкой, которая также валидирует формат status codes, response content schemas и ссылки на OAuth security schemes.
- Расширить OpenAPI-валидацию реальных HTTP-ответов и request fixtures с уже покрытых публичного bootstrap/provider, login/MFA, `/auth/me`, account sessions, password/profile/email, consent, token, UserInfo, Discovery и JWKS на административные integration-сценарии.

Критерий готовности: изменение имени, типа, обязательности или секретности поля ломает contract test до выпуска.

### 2. Завершить review admin UI

- Провести accessibility, keyboard navigation и responsive review Vue admin; разделить монолитный `views/admin/index.php` на модули.
- Добавить browser smoke для login/session restore, навигации, destructive confirmations, loading/error states и основных mobile breakpoints.

Критерий готовности: UI доступен с клавиатуры и screen reader, стабилен на поддерживаемых ширинах и не дублирует бизнес-правила API.

### 3. Удалить недостижимые provider presets и дублирование policy

- `IdentityProviderPolicy` и `BrokerService::applyPreset()` содержат ветки `google`/`github`, но модель и DB CHECK разрешают только type `oidc`; этот код недостижим.
- Выбрать один контракт: удалить presets и принимать явные OIDC endpoints либо формально добавить поддерживаемые provider types с миграцией и тестами.
- Удалить дублирование redirect/provider endpoint policy между `OAuthService`, `OAuthRedirectPolicy`, `BrokerService` и `IdentityProviderPolicy`.

Критерий готовности: каждый разрешённый type достижим и протестирован, а URI/SSRF policy имеет одну реализацию.

### 4. Обновить устаревший test/tooling stack

- Проект на PHP 8.4 остаётся на PHPUnit `^9.6`, поддержка bugfix этой ветки закончилась в 2024 году; перейти на поддерживаемую для PHP 8.4 major-ветку и обновить `phpunit.xml`/tests.
- Проверить переход PHPCS `^3.13` на актуальную major-ветку, устранив deprecations и сохранив PSR-12 gate.
- Добавить проверку direct outdated dependencies в регулярный maintenance workflow без автоматического небезопасного major upgrade.

Критерий готовности: тесты и линтер работают на поддерживаемых версиях без deprecation output, lock-файл и CI обновлены.

### 5. Усилить test coverage и supply-chain reproducibility

- Порог line coverage 35% слишком низок для auth/token/session/provider кода; ввести поэтапные пороги для security-critical services и controller HTTP branches.
- Включить protocol scripts в отчёт/матрицу покрытия или перенести сценарии в управляемый test harness с гарантированным cleanup.
- Закрепить GitHub Actions по commit SHA, а production/test base images — digest или проверяемый immutable tag; текущие `composer:2`, `php:8.4-fpm-bookworm`, `postgres:17` и action tags изменяемы.

Критерий готовности: критические ветви success/failure/replay/rollback покрыты, а повторная сборка использует проверенные immutable inputs с Dependabot-процессом обновления.
