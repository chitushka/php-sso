# План развития SSO PHP

## Правила ведения

- Это единственный активный backlog; завершённые исторические этапы здесь не хранятся.
- Порядок внутри приоритета задаёт рекомендуемую последовательность.
- Пункт закрывается после кода, миграции при необходимости, автоматических тестов и обновления документации.
- Новое внешнее поведение фиксируется в HTTP/API-контракте `ARCHITECTURE.md` и contract/integration tests.

## P1 — безопасность и корректность

### 1. Защитить federated login от login CSRF и replay

- Подписанный broker `state` содержит nonce, но не привязан к initiating browser и нигде атомарно не потребляется. Действительный state можно повторно использовать до истечения TTL и перенести в другой браузер.
- Хранить хеш одноразового state/nonce с TTL либо связывать его с защищённой browser cookie, атомарно помечать использованным до обмена code и очищать просроченные записи.
- Покрыть перенос state между браузерами, повтор callback, конкурентный callback, истечение и rollback при ошибке провайдера.

Критерий готовности: callback принимается ровно один раз и только в browser-контексте, начавшем federated login.

### 2. Разделить user и machine principals в авторизации и аудите

- `ApiController::requirePermission()` допускает `client_credentials`, но ряд контроллеров передаёт `$auth->id` как `actor_user_id`. Для OAuth client это нарушает FK `audit_logs.actor_user_id -> users.id`: signing-key rotation и federated unlink завершаются `500`, а LDAP update/delete могут сначала изменить данные и затем упасть на аудите.
- Явно определить операции, разрешённые machine clients. Для разрешённых операций хранить тип и идентификатор principal в аудите; для остальных требовать пользователя.
- Сделать mutation и обязательную audit-запись одной транзакцией во всех admin services.
- Добавить отсутствующие события как минимум для создания LDAP provider и OAuth client; не записывать успешное удаление несуществующего LDAP provider.

Критерий готовности: каждая admin-операция атомарна, корректно атрибутирована user/client principal и покрыта success, forbidden, rollback и client-credentials тестами.

### 3. Исправить доверие reverse proxy и production network perimeter

- `SSO_TRUSTED_PROXIES` используется только собственной обработкой IP и не заполняет Yii `Request::trustedHosts`; поэтому `X-Forwarded-Proto` удаляется, `isSecureConnection` остаётся false и заявленный HSTS не выставляется за штатным HTTPS proxy.
- Настроить доверенные forwarded proto/host/port только для разрешённых CIDR и проверить spoofing от недоверенного peer.
- Разделить development и production Compose exposure: текущий профиль публикует cleartext web `8080` и PostgreSQL `5433` наряду с HTTPS, что позволяет обойти TLS perimeter при сетевой доступности host ports.

Критерий готовности: за доверенным proxy приложение распознаёт HTTPS и отдаёт HSTS, недоверенные forwarded headers игнорируются, а production-профиль не публикует HTTP/DB ports наружу.

### 4. Закрыть нарушения вложенных ресурсов и audit lifecycle LDAP

- `DELETE /api/v1/ldap/providers/{id}/group-mappings/{mappingID}` не валидирует `{id}` и удаляет mapping другого provider; list mappings также возвращает пустой список вместо `404` для отсутствующего parent.
- Проверять существование parent и принадлежность mapping в одном запросе/транзакции; унифицировать `404` и idempotency по документированному контракту.
- Перестать очищать пропущенные поля LDAP provider неявными zero values либо явно описать полную replacement-семантику `PUT` типизированной схемой.

Критерий готовности: вложенный ресурс нельзя прочитать или удалить через чужой parent ID, а create/update/delete и audit откатываются совместно.

### 5. Довести DB-инварианты до фактической модели безопасности

- Добавить partial unique index, допускающий не более одного `active` OIDC signing key, и проверяемый repair path для нуля/нескольких активных ключей.
- Закрепить согласованность `users.source` с `password_hash`, `ldap_provider_id`/`ldap_dn` и federated links либо формализовать допустимые переходные состояния.
- Закрепить соответствие OAuth client type и secret (`confidential` с hash, `public` без secret), алгоритм signing key `RS256`, а также согласованность `status` и `expires_at` ключей.
- Миграция должна сначала диагностировать/нормализовать существующие нарушения и иметь rollback-тест.

Критерий готовности: некорректные состояния нельзя записать напрямую в PostgreSQL, deployment check диагностирует repairable drift, fresh/upgrade/down проверки проходят.

### 6. Усилить MFA lifecycle

- MFA login challenge возвращает фиктивного zero-user и timestamps `0001-01-01`, оставшиеся от исторического wire format, вместо минимального типизированного challenge response.
- Recovery codes имеют только 40 бит (`bin2hex(random_bytes(5))`) и хранятся как быстрый SHA-256; увеличить энтропию и использовать keyed/slow verification с безопасной миграцией или принудительной регенерацией.
- После enable/disable MFA пересмотреть отзыв существующих access/refresh tokens и sessions; текущий smoke прямо требует сохранения bearer token после отключения MFA.
- Добавить replay/concurrency тесты TOTP и recovery code, re-authentication policy для изменения MFA и проверку отсутствия MFA-секретов в ответах/логах.

Критерий готовности: MFA response не содержит фиктивных сущностей, recovery codes устойчивы к offline перебору, а изменение MFA инвалидирует credentials согласно документированной политике.

### 7. Унифицировать access-token claims и валидацию

- First-party токены из `AuthService::issueAccessToken()` не содержат `iss`, `aud` и явного purpose, а `authenticate()` проверяет issuer/audience только при наличии `client_id` или `client_credentials`.
- Ввести единый профиль access token, всегда проверять тип, issuer, audience, временные claims и revocation; отделить browser/admin tokens от OAuth resource tokens, если у них разные аудитории.
- Покрыть wrong issuer/audience, future `iat`/`nbf`, неверный purpose, disabled client/user и key/secret rotation.

Критерий готовности: ни один подписанный JWT другого назначения или аудитории не принимается как API access token.

### 8. Сделать operational status достоверным и fail-closed

- Готовность signing keys определяется только количеством строк `active`, без дешифрования private key и пробной подписи; повреждённый ключ отображается как ready.
- После успешного `SELECT 1` последующие запросы summary/cleanup не перехватывают сбой БД и превращают status endpoint в необработанный `500`.
- Добавить возраст последнего cleanup относительно настроенного интервала и различать degraded components без раскрытия внутренних ошибок.

Критерий готовности: corrupt key, stale cleanup и потеря БД отражаются структурированным `degraded`, endpoint не раскрывает исключения и не сообщает ложную готовность.

## P2 — архитектура и поддерживаемость

### 1. Сделать OpenAPI действительно машиночитаемым контрактом

- Сейчас 53 ответа используют общий `JsonSuccess`, 16 request body — `JsonObject`, 11 списков — `JsonPage`; проверка `openapi_contract_check.php` в основном сопоставляет методы и paths и не обнаруживает drift полей/status codes/security requirements.
- Ввести отдельные schemas для admin resources, inputs, pages и error responses, required/nullable/additionalProperties, OAuth security schemes и полный набор `400/401/403/404/409/429/500`.
- Валидировать реальные HTTP-ответы и request fixtures по OpenAPI в integration tests.

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
