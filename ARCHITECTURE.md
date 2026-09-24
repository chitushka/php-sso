# Архитектура проекта

## Назначение

Проект является PHP/Yii2 переносом single-tenant SSO/IdP сервиса из Go. Multi-tenancy/realms не входят в область задачи. Основная цель архитектуры — сохранить доменные границы исходного сервиса и постепенно заменить Go-модули эквивалентами на PHP 7.4.

## Технологический стек

- PHP 7.4
- Yii2
- PostgreSQL
- Docker Compose
- VueJS в Yii2 layout без отдельной frontend-сборки

Локальное окружение разработчика не обязано содержать PHP или Composer: запуск, миграции и проверки выполняются внутри Docker-контейнера `app`.

## Слои

```text
HTTP Controller -> Service -> ActiveRecord Model -> PostgreSQL
```

Контроллеры находятся в `controllers/`, бизнес-логика в `services/`, модели таблиц в `models/`, миграции в `migrations/`, публичная входная точка в `web/`, UI в `views/admin/` и `web/css/`.

Для `OAuthController` задан явный `controllerMap`: Yii2 иначе преобразует имя класса в controller id `o-auth`, а внешний контракт Go-сервиса использует стабильный маршрут `oauth/*`.

## Модули

- `BootstrapController` — первичная инициализация администратора.
- `AuthController` + `AuthService` — локальный вход, cookie-сессии, Bearer JWT, отзыв сессий.
- `UsersController` — административный CRUD пользователей и soft delete; создание возвращает Go-compatible 400 для пустых username/email и 409 для конфликта username, path UUID валидируются до PostgreSQL-запроса, pagination использует Go-границу limit 100.
- `RbacController` + `RbacService` — роли, permissions, назначение ролей пользователям и permissions ролям; create role/group возвращает Go-compatible 400 для пустых code/name и 409 для конфликта code, role/permission/user path UUID валидируются с точными 400-ошибками до обращения к PostgreSQL.
- `GroupsController` — группы, роли групп и членство пользователей в группах; group/user/role UUID валидируются в Go-compatible порядке до service/DB слоя.
- `LdapController` + `LdapService` — CRUD LDAP-провайдеров, test bind и group mappings.
- `LdapService::authenticate()` — LDAP Search + Bind fallback для `AuthService::login()`, auto-provisioning LDAP-пользователей и синхронизация mapped groups.
- `MfaService` — TOTP setup/enable/disable, Go-compatible `enroll`/`activate` aliases, одноразовые recovery codes и MFA challenge для login.
- `AccountService` + `MailService` — password forgot/reset/change, email verification и доставка reset/verification ссылок через SMTP или лог в dev-режиме.
- `BrokerController` + `BrokerService` — identity brokering для Google/GitHub/generic OIDC, admin CRUD identity providers с Go-compatible ошибками создания, signed state, callback, linking/JIT provisioning federated users.
- `OAuthController` + `OAuthService` — OAuth-клиенты, authorization code, refresh, revoke, introspect, consent; административные client CRUD/rotate-secret маршруты валидируют path UUID до обращения к PostgreSQL. Create возвращает Go-обёртку `{client, client_secret}`, использует zero values (`enabled=false`, пустые массивы) для пропущенных полей; update полностью заменяет поля `UpdateClientInput` и не меняет тип клиента.
- `AuditController` — фильтрация аудита по action, actor и RFC3339-диапазону. Actor проходит строгую UUID-проверку, дробные секунды RFC3339 нормализуются до точности PostgreSQL, а `limit`/`offset` повторяют `strconv.Atoi` и границы Go-handler.
- `HealthController` — публичные live/ready/version probes. Readiness выполняет `SELECT 1` и при ошибке PostgreSQL возвращает Go-compatible 503 `database unavailable`, не раскрывая детали исключения.
- `BootstrapController` + `BootstrapService` — одноразовое создание первого локального администратора. Сервис повторяет порядок Go-валидации, оставляет email неподтверждённым, назначает роль `admin` и пишет `bootstrap_completed`; PostgreSQL integration-тест выполняется в транзакции с полным rollback существующих данных.
- `OidcController` + `OidcService` — discovery, JWKS, RS256 ID Token, UserInfo.
- `AuditController` — чтение журнала аудита с Go-compatible валидацией фильтров `actor`, `from`, `to`.
- `AuditService` — запись важных событий.
- `CryptoService` — AES-256-GCM шифрование секретов без Composer-зависимостей.
- `RateLimitService` — token bucket на Yii2 cache для чувствительных auth/OAuth/bootstrap маршрутов.
- `MetricsService` + `MetricRoute` — dependency-free Prometheus text метрики HTTP-запросов на `/metrics`, сохранённые через Yii2 cache. Route labels преобразуются из внутренних Yii routes в публичные chi-совместимые шаблоны Go; path UUID не создают высокую кардинальность.
- `TrustedRequest` — безопасное разрешение client IP: `X-Forwarded-For` учитывается только от `SSO_TRUSTED_PROXIES`.
- `CleanupController` — console-команда удаления истёкших auth/OAuth артефактов.
- `models/*` — ActiveRecord-представление схемы Go-сервиса.

## База данных

Миграция `m260907_000001_init_sso_schema` создаёт актуальную PostgreSQL-схему из Go-миграций `000001-000010`: users, sessions, audit_logs, oauth_clients, authorization codes, OIDC signing keys, refresh tokens, RBAC, groups, LDAP, MFA, one-time tokens и federation.

UUID генерируются PostgreSQL через `pgcrypto/gen_random_uuid()`.

## Конфигурация

Все runtime-настройки используют namespace `SSO_*`. Основные переменные: `SSO_DATABASE_URL`, `SSO_ISSUER`, `SSO_JWT_SECRET`, `SSO_ENCRYPTION_KEY`, `SSO_ACCESS_TOKEN_TTL`, `SSO_SESSION_TTL`, `SSO_REFRESH_TOKEN_TTL`.

Rate limiting настраивается через `SSO_RATE_LIMIT_PER_MINUTE` и `SSO_RATE_LIMIT_BURST`. По умолчанию token bucket хранится в `FileCache`; для нескольких app-инстансов можно задать `SSO_CACHE_DRIVER=db`, тогда Yii2 cache использует таблицу `cache` в PostgreSQL. Максимальный размер тела запроса задаёт `SSO_MAX_BODY_BYTES`. `SSO_TRUSTED_PROXIES` содержит comma-separated IP/CIDR reverse proxy, которым разрешено задавать `X-Forwarded-For`.

## Защитные механизмы

`AuthService` хранит неудачные попытки входа в `login_attempts`. После 5 ошибок пара login/IP блокируется с экспоненциальной задержкой от 1 до 15 минут. Успешный вход удаляет счётчик.

Session API повторяет контракт Go-сервиса: `GET /api/v1/auth/sessions` возвращает только неотозванные и неистёкшие сессии текущего пользователя, не раскрывает `token_hash`, но сохраняет поле `user_id`; `DELETE /api/v1/auth/sessions/{id}` валидирует UUID и не позволяет отзывать чужую сессию; `DELETE /api/v1/auth/sessions` отзывает все сессии и выставляет `tokens_invalid_before`, немедленно делая ранее выданные first-party Bearer JWT недействительными.

JWT содержит время с точностью до секунды, а PostgreSQL cutoff — до микросекунд. Поэтому при выдаче нового first-party JWT после revoke-all `AuthService` устанавливает `iat` не раньше чем `tokens_invalid_before + 1 секунда`: старые токены остаются отозванными, а немедленный повторный login не попадает в секундную гонку cutoff.

`ApiController` применяет общий лимит тела запроса и вызывает `RateLimitService` для `auth/login`, `bootstrap/create`, `oauth/token`, `oauth/revoke`, `oauth/introspect`.

`ApiController::isUuid()` централизует синтаксическую проверку UUID path-параметров. URL rules Users, Sessions, Roles/Permissions, Groups, Identity Providers, LDAP Providers и административных OAuth clients пропускают произвольный непустой сегмент в соответствующий controller, чтобы невалидный UUID возвращал JSON-ошибку API, а не попадал в Vue SPA fallback. В permission/group/role assignment POST невалидный UUID из JSON соответствует Go decode error `invalid json body`; составные user-role, user-group и group-role маршруты валидируют идентификаторы слева направо, как исходные handlers. Identity provider PUT/DELETE возвращают `invalid id` до service/DB слоя и `provider not found` для отсутствующего UUID. LDAP provider PUT/DELETE/list/create mappings используют `invalid id`; DELETE mapping повторяет Go handler, валидируя только `mappingID` как `invalid mapping id` и не используя parent provider ID. OAuth client GET/PUT/DELETE/rotate-secret возвращают `invalid id` до service/DB слоя; rotate-secret отдельно сохраняет Go-ошибку `confidential client not found` для отсутствующего клиента.

`app\components\JsonParser` нормализует ошибки malformed и non-object JSON до Go-compatible `400 {"error":"invalid json body"}`. Parser вызывается лениво через `Request::getBodyParams()`, поэтому path UUID продолжает валидироваться раньше body на тех маршрутах, где такой порядок задан исходным Go handler. JSON `null` сохраняет поведение Go decoder для struct и преобразуется в пустой набор параметров. `ApiController::body()` дополнительно принимает allowlist полей: auth/account/bootstrap, Users/Roles/Groups CRUD/assignments и OAuth/Identity/LDAP admin actions используют её как эквивалент Go `json.Decoder.DisallowUnknownFields()` и отклоняют лишние ключи той же ошибкой до бизнес-логики. User update принимает только поля Go `UpdateUserInput`; password и `email_verified` через этот маршрут больше не изменяются. OAuth client update соответствует `UpdateClientInput` и не позволяет менять `type`.

`AuthService::authenticate()` централизует Go-compatible Bearer middleware contract: отсутствие заголовка возвращает `missing bearer token`, ошибка подписи/claims — `invalid token`, неактивный пользователь — `account is not active`, а first-party JWT старше `tokens_invalid_before` — `token revoked`. `ApiController::requireUser()` применяет этот контракт к self-service/session API, `requirePermission()` — к административным endpoint-ам перед проверкой права через `RbacService`, а UserInfo использует ту же классификацию. `RbacService` учитывает как прямые роли из `user_roles`, так и роли, унаследованные через `user_groups -> group_roles`.

LDAP bind password сохраняется в `ldap_providers.bind_password` в формате `v1:<base64>` через `CryptoService`. Ответы API не возвращают секрет. При update пустой `bind_password` сохраняет уже записанный секрет.

При локальном login `AuthService` сначала проверяет локальный `password_hash`; если проверка не прошла, `LdapService` перебирает включённые LDAP providers, выполняет service bind, ищет пользователя по `user_filter`, затем проверяет пароль bind-ом найденного DN. LDAP-пользователь создаётся или обновляется в `users` с `source=ldap`, `ldap_provider_id` и `ldap_dn`. Если найден локальный пользователь с тем же username, LDAP login отклоняется, чтобы не захватить локальный аккаунт. LDAP group mappings синхронизируются в `user_groups` с `source=ldap`; ручные назначения не удаляются.

Для пользователей с `mfa_enabled=true` успешная проверка пароля не создаёт сессию сразу: `AuthService` создаёт `one_time_tokens.purpose=mfa_login` и возвращает `mfa_token` плюс legacy alias `challenge_token`. `POST /api/v1/auth/mfa/verify` принимает `mfa_token` или `challenge_token` и TOTP/recovery code, после чего создаёт обычную cookie-сессию и Bearer JWT. Неизвестный, истёкший или уже использованный challenge возвращает 401 `invalid mfa token`, а неверный второй фактор — 401 `invalid code`. Ошибки кода записываются в общий `login_attempts` по username/IP, поэтому после пяти ошибок последующие попытки получают 429; успешная MFA-проверка сбрасывает счётчик. TOTP enrollment сохраняет encrypted secret в `users.mfa_secret` до активации; доступны Go-compatible маршруты `/api/v1/auth/mfa/enroll` и `/api/v1/auth/mfa/activate`, а также PHP aliases `/setup` и `/enable`. Повторный activate возвращает 409 `enroll first`; disable различает неверный код и неправильное состояние, а при успехе возвращает `mfa_disabled`. Как в Go-сервисе, отключение MFA не отзывает текущий Bearer JWT. `mfa_last_used_counter` блокирует повтор counter, recovery codes хранятся в `mfa_recovery_codes` как password hash и помечаются `used_at`.

`AccountService` использует `one_time_tokens.purpose=password_reset` и `email_verify` с SHA-256 hash от сырого токена. `POST /api/v1/auth/password/forgot` всегда возвращает 200 при валидном body, чтобы не раскрывать наличие пользователя; как Go-handler, malformed/non-object/unknown-field body этого маршрута возвращает 400 `login is required`, а не общий `invalid json body`. Reset-ссылка отправляется через `MailService`, а без SMTP пишется в лог категории `mail`. `POST /api/v1/auth/password/reset` меняет пароль локального аккаунта, отзывает все `sessions`, `refresh_tokens` и выставляет `tokens_invalid_before`. Cutoff применяется только к first-party JWT без `client_id`: внешний OAuth access token продолжает работать до `exp`, но лишается уже отозванного refresh token. Заблокированный/удалённый аккаунт отвергается независимо от типа токена. `POST /api/v1/auth/password/change` доступен только локальным аккаунтам и требует текущий пароль. `PUT /api/v1/auth/profile` позволяет текущему пользователю обновлять `first_name`, `last_name` и scalar `attributes` без admin-права `users:update`. `POST /api/v1/auth/email/verify` помечает email подтверждённым и переводит `pending`-аккаунт в `active`.

`BrokerService` хранит identity provider client secrets в `identity_providers.client_secret` через `CryptoService`. `GET /api/v1/broker/providers` отдаёт только включённые провайдеры для login UI. `GET /oauth2/broker/{code}/login` создаёт HMAC-signed state на 10 минут и перенаправляет во внешний IdP; state совместим с Go wire-format: JSON expiry — RFC3339, подпись использует `SSO_JWT_SECRET`, payload и signature кодируются строгим unpadded base64url. Это позволяет завершать начатый login на другом Go/PHP-инстансе во время rolling migration. Callback обменивает authorization code, читает userinfo и создаёт обычную SSO cookie-сессию плюс Bearer JWT во fragment `#broker_token=...`. Линковка существующего аккаунта разрешена только при `email_verified=true` от IdP; MFA-защищённые локальные аккаунты не линкуются автоматически. Если подходящего аккаунта нет, создаётся `source=federated` пользователь и запись `federated_identities`.

Refresh token rotation сохраняет `refresh_tokens.family_id` между поколениями. При refresh grant старый token помечается `rotated_at` атомарным `UPDATE ... WHERE rotated_at IS NULL`; если token уже rotated или параллельный запрос проиграл этот guard, вся refresh family получает `revoked_at`, а в audit пишется `refresh_token_reuse_detected`. RFC 7009 revocation также отзывает всю найденную family, игнорирует неизвестный или принадлежащий другому клиенту token, записывает `refresh_token_revoked` и отвечает пустым body. RFC 7662 introspection сначала ищет refresh token и возвращает его состояние с `token_type=refresh_token`, `client_id`, `sub`, `scope`, `exp`, `iat`; rotated, revoked и expired записи неактивны. Для JWT access token возвращается Go-compatible подмножество claims без внутренних `iss`/`iat`. Token/revoke/introspect используют единый RFC 6749 Basic parser, где корректный Basic header имеет приоритет над form credentials, а `invalid_client` сопровождается `WWW-Authenticate: Basic realm="sso"`.

Для public OAuth clients `OAuthService::authorize()` требует `code_challenge_method=S256` и непустой `code_challenge`; отсутствие PKCE возвращает RFC-style `invalid_request` с описанием `PKCE S256 required`. Token exchange потребляет authorization code атомарным `UPDATE ... WHERE used_at IS NULL AND expires_at > now() RETURNING ...` до проверки client, redirect URI и PKCE, повторяя порядок Go `ConsumeCode` и исключая параллельный обмен. Любой непустой challenge проверяется как S256 независимо от сохранённого method; неудачная проверка сжигает одноразовый code.

Consent API повторяет Go lifecycle. `GET /oauth2/consent` сначала проверяет активную SSO-сессию, затем включённого OAuth-клиента и допустимость scope; успешный ответ содержит `client_id`, `client_name`, `requested_scopes` и ранее сохранённые `granted_scopes`. `POST /oauth2/consent` сохраняет отдельный OAuth error envelope: ошибки malformed/non-object JSON и неизвестные поля возвращаются как `400 {"error":"invalid_request","error_description":"invalid json body"}`, а не как общий API error. Разрешены только поля `client_id` и `scope`, как в анонимной Go request-структуре. Повторная выдача согласия объединяет новые scopes с существующими вместо замены и записывает `consent_granted` в аудит.

`OidcController::actionUserinfo()` фильтрует claims по scope OAuth access token: `profile` раскрывает `preferred_username` и `source`, `email` раскрывает `email`. Локальные first-party Bearer tokens без `scope` сохраняют полный исторический ответ.

`OAuthService::logout()` реализует RP-initiated logout: валидирует RS256 `id_token_hint` по stored OIDC signing key, issuer и возрасту hint; отзывает текущую `sso_session` cookie-сессию и refresh tokens пользователя; `post_logout_redirect_uri` разрешён только если он зарегистрирован у клиента из `id_token_hint`. Как Go handler, непустые form-параметры POST имеют приоритет над одноимёнными query-параметрами. Если у клиента задан `backchannel_logout_uri`, `OidcService` создаёт RS256 logout token со сроком две минуты, уникальным `jti` и event `http://schemas.openid.net/event/backchannel-logout`, затем отправляет его как `application/x-www-form-urlencoded`; сетевой отказ не отменяет локальный logout.

`OidcService` лениво проверяет lifecycle signing keys при выдаче и проверке токенов и при построении JWKS. Active RSA-ключ старше 30 дней атомарно переводится в `retiring` с 24-часовым grace-периодом, а новый ключ создаётся под PostgreSQL advisory lock. JWKS публикует active и retiring keys, поэтому ранее выданные ID/logout tokens продолжают проверяться до конца grace-периода; после него ключ получает статус `retired` и исчезает из JWKS.

RS256 ID Token повторяет claim contract Go-сервиса: `auth_time` фиксирует `created_at` обменённого authorization code, а при refresh — `created_at` использованного refresh token. Claim `nonce` сериализуется всегда; для refresh grant он имеет пустое строковое значение.

OAuth authorize и RP-initiated logout собирают redirect URI через разбор query/fragment: `code` и `state` заменяют одноимённые зарегистрированные параметры, остальные query-параметры сохраняются, а fragment остаётся последней частью URI. Это повторяет `url.Query().Set()` Go-сервиса и не допускает попадания OAuth-параметров после `#`.

OAuth token/revoke/introspection разбирают HTTP Basic credentials по RFC 6749 §2.3.1 как `application/x-www-form-urlencoded`: `+` становится пробелом, а `%2B` — литеральным плюсом. Корректный Basic header по-прежнему имеет приоритет над form credentials.

Успешный `client_credentials` grant возвращает `access_token`, `token_type`, `expires_in` и подтверждённый непустой `scope`, но не выдаёт refresh или ID Token. Для пустого scope поле не сериализуется, повторяя Go `json:"scope,omitempty"`.

HS256 access tokens повторяют Go `auth.Claims`: общие identity claims — `sub`, `username`, `email`, `source`, `iat`, `exp`; OAuth user token дополнительно несёт `client_id` и непустой `scope`, а service-account token — `source=client` и `purpose=client_credentials`. Claim `iss` отсутствует, поскольку Go issuer заполняет его только в RS256 OIDC ID/logout tokens.

`JwtService::verify()` не выбирает алгоритм из недоверенного token header: verifier принимает только `alg=HS256`, строго декодирует все три compact JWT-сегмента как unpadded base64url, сравнивает raw HMAC-SHA256 и требует числовой `exp`, находящийся строго в будущем. Необязательный `nbf` также должен быть числовым и не превышать текущее время. Это повторяет проверку signing method, encoding и NumericDate в Go JWT library и закрывает algorithm-confusion и преждевременное принятие токена в PHP-порте.

`TrustedRequest` возвращает `Yii::$app->request->userIP` из прямого peer address, если peer не входит в `SSO_TRUSTED_PROXIES`. Для trusted proxy берётся правый самый не-trusted IP из `X-Forwarded-For`, что сохраняет per-IP brute-force lockout и rate limit за reverse proxy без доверия к spoofed headers.

`php yii cleanup/run` удаляет истёкшие/отозванные `sessions`, `oauth_authorization_codes`, `refresh_tokens`, `one_time_tokens` и старые `login_attempts`. В production команду нужно запускать cron-ом или аналогичным scheduler-ом примерно раз в час.

`GET /metrics` отдаёт Go-compatible Prometheus text exposition с `sso_http_requests_total`, `sso_http_inflight`, `sso_http_request_duration_seconds_sum` и `sso_http_requests_processed_total`. Labels `route` используют публичные chi-шаблоны (`{id}`, `{userID}`, `{roleID}` и другие), а SPA fallback получает `other`. В PHP-порте counters хранятся через Yii2 cache, поэтому для нескольких app-инстансов рекомендуется `SSO_CACHE_DRIVER=db`.

`tests/Integration/oauth_flow.php` — dependency-free HTTP smoke test, который поднимается через `make integration`, создаёт временных пользователей/клиенты в PostgreSQL, проверяет OIDC Discovery/JWKS, login, malformed/non-object JSON contracts, Users GET/PUT/DELETE invalid UUID и missing user contracts, Roles CRUD/permissions/user-role invalid UUID contracts, Groups CRUD/group-role/user-group invalid UUID contracts, Identity Provider PUT/DELETE invalid UUID и missing provider contracts, LDAP provider/mapping invalid UUID и missing resource contracts, OAuth client GET/PUT/DELETE/rotate-secret invalid UUID и missing client contracts, session list/revoke/revoke-all, сохранение внешнего OAuth access token после revoke-all и invalid session UUID, OAuth2 Authorization Code + PKCE, `client_secret_post` и `client_secret_basic`, одноразовость authorization code, RS256 `id_token` claims, UserInfo scope filtering, introspection active/inactive token, revocation refresh token, client_credentials token contract, consent-required/grant flow, RP logout redirect validation, RS256 back-channel logout через локальный mock RP, refresh rotation/reuse detection, RBAC enforcement и базовые OAuth error contracts для authorize/token/revoke/introspect, затем удаляет созданные записи. Цель `make integration` поднимает app и back-channel mock PHP servers с высоким test-local rate limit, чтобы расширенный smoke flow проверял endpoint-контракты без ложных 429.

Users admin update повторяет replace-семантику Go `UpdateUserInput`, а не PATCH: `username`, `email`, `status`, `first_name`, `last_name` и `attributes` формируются только из текущего PUT body. Отсутствующие необязательные profile-поля поэтому очищаются; `source`, password/MFA/email verification и LDAP linkage этим маршрутом не изменяются.

`tests/Integration/oauth_client.php` — dependency-free внешний RP client flow, запускаемый через `make integration-client`. Сценарий читает OIDC Discovery, формирует authorize URL, проверяет callback redirect/state, обменивает code через `client_secret_basic`, валидирует базовые `id_token` claims, вызывает UserInfo, refresh token и RP-initiated logout. Для тестового сервера цель задаёт `SSO_ISSUER=http://127.0.0.1:8081`, чтобы discovery endpoint-ы указывали на локальный app.

`phpunit.xml.dist` и `tests/bootstrap.php` задают PHPUnit-набор для unit-проверок без PostgreSQL. `tests/Unit/PasswordPolicyTest.php` проверяет границы `PasswordPolicy` 12/128 символов, а `tests/Unit/TrustedRequestTest.php` покрывает trusted-proxy IP resolution в `TrustedRequest`: игнорирование spoofed `X-Forwarded-For` от недоверенного peer, выбор rightmost untrusted IP, IPv6 CIDR и нормализацию IPv4 peer address с портом. Цель `make unit` запускает `vendor/bin/phpunit` внутри Docker-контейнера `app`.

`tests/Integration/OAuthClientServiceTest.php` — первый PHPUnit integration test против PostgreSQL. Цель `make phpunit-integration` применяет Yii2-миграции и запускает suite `Integration`; тест создаёт уникальные OAuth clients через `OAuthService`, проверяет confidential/public поведение, PostgreSQL `TEXT[]` поля через `OAuthClient::parsePgArray()` и audit side effects, затем удаляет свои fixture-записи.

`tests/Integration/AuthServiceTest.php` покрывает local auth service поверх PostgreSQL: создаёт локального пользователя, проверяет failed-login счётчик и его сброс после успешного входа, выдачу SSO session cookie, Bearer JWT lookup через `currentUser()`, audit `login_success`, отзыв session через logout, фильтрацию истёкших/отозванных сессий, точечный отзыв активной сессии, MFA token/code errors и brute-force lockout второго фактора.

`tests/Integration/OAuthTokenServiceTest.php` покрывает service-level OAuth token flows без HTTP-сервера: создаёт пользователя и confidential client, получает SSO session через `AuthService`, вызывает `OAuthService::authorize()`, обменивает code на access/refresh tokens через `OAuthService::token()`, проверяет Bearer JWT lookup, сохранение used authorization code, запрет повторного обмена того же code, refresh token rotation, reuse detection с отзывом всей family и revoke refresh token.

`tests/Integration/OidcServiceTest.php` покрывает OIDC service/controller flows поверх PostgreSQL и web `Request`: RS256 ID token hint issue/verify, JWKS, UserInfo scope filtering для `profile`/`email`/first-party токенов, а также RP-initiated logout через `OAuthService::logout()` с registered `post_logout_redirect_uri`, state passthrough, отзывом refresh tokens и audit `logout`.

`tests/Integration/AccountServiceTest.php` покрывает account security flows поверх PostgreSQL: password reset через одноразовый hashed token, отзыв sessions/refresh tokens и ранее выданного first-party JWT при сохранении external OAuth access token, блокировку external token для неактивного пользователя, запрет повторного использования reset token, email verification для `pending` аккаунта и validation/normalization scalar profile attributes.

`tests/Integration/MfaServiceTest.php`, `tests/Integration/RbacServiceTest.php` и `tests/Integration/AdminProviderServiceTest.php` закрывают оставшиеся service-level проверки без внешней сети: MFA enroll/enable/login challenge/recovery code/disable, RBAC direct/group-inherited permissions и idempotent assignments, identity provider CRUD с encrypted secret preservation, public broker start URL, LDAP provider CRUD с encrypted bind password preservation и LDAP group mappings. `make integration-broker` дополнительно поднимает локальный mock OIDC provider из `tests/Fixtures/oidc_provider` и проверяет полный broker login/callback flow через HTTP без внешней сети.

Маршруты broker login/callback используют path-параметр `<providerCode>`, а не `<code>`, чтобы он не конфликтовал с OAuth callback query-параметром `code`. Это важно для Yii2 URL manager: иначе provider code может затереть внешний authorization code в `Yii::$app->request->get('code')`.

`tests/Integration/ldap_bind_flow.php` — optional smoke для реального LDAP test fixture. Цель `make integration-ldap` применяет миграции и запускает скрипт; без `SSO_TEST_LDAP_HOST` он безопасно пропускается. При заданных `SSO_TEST_LDAP_HOST`, `SSO_TEST_LDAP_BIND_DN`, `SSO_TEST_LDAP_BIND_PASSWORD`, `SSO_TEST_LDAP_BASE_DN`, `SSO_TEST_LDAP_LOGIN`, `SSO_TEST_LDAP_PASSWORD` он создаёт временный LDAP provider, проверяет service bind, search + user bind, auto-provisioning пользователя, optional mapping через `SSO_TEST_LDAP_GROUP_DN` и audit `ldap_login_success`.

## UI

Админский интерфейс обслуживается Yii2 маршрутом `AdminController::actionIndex()` и использует VueJS на одной странице. Текущий UI покрывает bootstrap, login с MFA challenge и external sign-in buttons, forgot/reset password, account profile/password/email verification, OAuth clients, identity providers, users, roles, permissions, groups, LDAP providers, audit и базовое управление MFA текущего аккаунта. Формы назначений работают поверх RBAC API: роли пользователям, группы пользователям, permissions ролям и роли группам. Следующие UI-страницы должны переноситься по модулям: production/admin maintenance и тестовые сценарии совместимости.

Vue MFA flow использует канонический Go-контракт: читает и отправляет `mfa_token`, вызывает `/api/v1/auth/mfa/enroll` и `/activate`, а activate body содержит только `code`. Старые PHP route/response aliases пока сохранены для обратной совместимости, но UI от них больше не зависит.

## Документация

При каждом функциональном изменении нужно обновлять:

- `PLAN.md` — статус и порядок переноса.
- `ARCHITECTURE.md` — архитектурные решения и границы модулей.
- `README.md` — пользовательские команды, возможности и важные изменения.
- `MIGRATION_NOTES.md` — операционный порядок cutover/rollback при изменениях, влияющих на переход с Go-сервиса.

## Переход с Go-сервиса

`MIGRATION_NOTES.md` фиксирует production cutover с Go-версии на PHP/Yii2: совместимые публичные маршруты, подготовку `.env`, порядок применения Yii2-миграций, smoke-checklist после переключения reverse proxy и rollback через дамп PostgreSQL. Документ намеренно отделён от архитектуры, потому что описывает операционную процедуру, а не runtime-модуль приложения.
