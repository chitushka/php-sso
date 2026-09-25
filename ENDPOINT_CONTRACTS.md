# Дифференциальная сверка endpoint-контрактов

Контрольный источник: Go handlers и request/response models в `D:\workspace\GolandProjects\sso\internal`. Сверка завершена 2026-09-25.

| Группа | Go routes | Результат сверки |
| --- | ---: | --- |
| Health / metrics / bootstrap | 6 | Методы, статусы, JSON и порядок bootstrap-проверок совпадают |
| Auth / sessions | 6 | LoginResult, cookie, MFA zero values, logout и revoke-сценарии совпадают |
| Password / email / MFA | 9 | JSON-схемы, Bearer-before-body порядок и ошибки совпадают |
| Users | 5 | Pagination, UUID, replace PUT, ошибки и audit совпадают |
| Roles / permissions / user roles | 11 | CRUD, assignments, UUID/body errors и audit actions совпадают |
| Groups / user groups | 9 | CRUD, assignments, порядок UUID-проверок и audit совпадают |
| Audit | 1 | Фильтры, RFC3339 и pagination совпадают |
| LDAP | 8 | Provider/mapping structs, defaults, errors, delete semantics и audit совпадают |
| Identity broker | 7 | Public/admin routes, redirects, provider structs и audit совпадают |
| OAuth2 / OIDC | 17 | Client CRUD, protocol errors, Discovery/JWKS/UserInfo/ID Token и logout совпадают |

Для всех JSON endpoint-ов PHP повторяет `encoding/json` + `DisallowUnknownFields`: имена полей сопоставляются без учёта регистра, неизвестные поля и несовместимые JSON-типы дают handler-specific 400, `null` сохраняет Go zero-value семантику, UUID и `time.Time` проверяются при decode. UUID path/body поддерживают те же standard/raw/URN/Microsoft формы, что `google/uuid.Parse`, и нормализуются перед PostgreSQL.

Audit side effects сверены отдельно: совпадают action, actor, target type/id и наличие IP/User-Agent. В частности, отсутствующие в Go события `oauth_client_created`, `user_updated`, `sessions_revoked_all` и внутренние MFA-события не создаются; rotate-secret пишет `oauth_client_secret_rotated` с `client_id`.

Хэши новых user passwords и OAuth client secrets создаются в Argon2id PHC-формате с Go-параметрами `m=65536,t=3,p=2`, поэтому записи остаются двусторонне совместимыми при rolling migration.

Дополнительные PHP-маршруты `/api/v1/auth/profile`, `/api/v1/auth/mfa/setup`, `/enable` и `/recovery-codes` не заменяют и не изменяют Go routes. Они считаются документированными расширениями PHP-порта и не участвуют в rolling-migration трафике.
