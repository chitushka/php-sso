# Модель угроз и профиль безопасности

## Границы доверия

SSO PHP доверяет только собственной PostgreSQL, явно настроенным reverse proxy и явно разрешённым LDAP/SMTP/OIDC endpoints. Браузер, OAuth client, forwarded headers, DNS, внешние провайдеры и ответы transport-сервисов считаются недоверенными.

Основные возможности атакующего: создавать cross-site запросы из браузера пользователя, подменять параметры OAuth/OIDC, повторно использовать перехваченные одноразовые артефакты, управлять внешним endpoint или DNS-ответом, присылать oversized/chunked ответы и вредоносные proxy headers. Компрометация runtime host, PostgreSQL или корневого encryption/signing key не покрывается прикладными мерами и требует инфраструктурного incident response.

## Browser session, Bearer token, consent и logout

- `sso_session` передаётся только в HttpOnly cookie с SameSite=Lax и Secure в production. Сырой session token не возвращается в JSON.
- Login возвращает отдельный случайный CSRF token и одноимённую доступную JavaScript cookie `sso_csrf`. Cookie-authenticated изменяющие запросы должны передать то же значение в `X-CSRF-Token`; cross-site Fetch Metadata и чужой Origin отклоняются.
- Bearer-аутентифицированные API-запросы не используют cookie principal и не требуют CSRF token. Bearer tokens нельзя помещать в URL, logs или browser storage без отдельного анализа риска.
- Выдача consent требует активной session и CSRF. Пользователь может просмотреть `GET /api/v1/auth/consents` и отозвать consent через `DELETE /api/v1/auth/consents/{clientId}`; отзыв также отзывает refresh tokens этого клиента.
- GET logout допускается только как RP-initiated logout: валидный `id_token_hint` должен принадлежать текущей активной session. Cookie-only cross-site GET не завершает session. POST logout требует CSRF.
- Auth/token responses имеют `Cache-Control: no-store`; responses получают CSP, `Permissions-Policy`, `X-Content-Type-Options`, `Referrer-Policy`, а production HTTPS — HSTS.

## Поддерживаемый OAuth 2.0/OIDC профиль

- Authorization endpoint поддерживает только Authorization Code flow (`response_type=code`). Implicit и hybrid flows не поддерживаются.
- `state` обязателен для каждого authorization request. Для scope `openid` также обязателен `nonce`.
- PKCE S256 обязателен для public и confidential clients; `plain` не поддерживается.
- Authorization errors перенаправляются только на предварительно проверенный зарегистрированный redirect URI и включают `state` и `iss`. Ошибка неизвестного client или незарегистрированного URI возвращается локально.
- Redirect и post-logout URI регистрируются без credentials и fragment. По умолчанию требуется HTTPS. HTTP loopback разрешается только public client с явным `allow_loopback_redirects`; при authorization допускается переменный port того же loopback host/path.
- Confidential client использует ровно один способ аутентификации: HTTP Basic или body credentials. Public client не передаёт secret и не получает `client_credentials` grant.
- ID Token и OAuth access token содержат ожидаемый `iss`; ID Token проверяет `aud`, `azp`, `exp`, `iat` с ограниченным clock skew. Retiring signing key публикуется не меньше максимального срока жизни проверяемого token плюс skew.
- Authorization code и refresh token имеют exactly-once/concurrency protection; refresh reuse отзывает всю token family.

Негативная матрица тестов покрывает незарегистрированные/небезопасные URI, отсутствующие `state`/`nonce`/PKCE, PKCE `plain`, смешанную client authentication, replay authorization code/refresh token, consent CSRF и logout redirect.

## Исходящие соединения и proxy

- Outbound HTTP принимает HTTPS и только явно allowlisted внутренний HTTP, не следует redirects, проверяет все DNS addresses и повторно проверяет выбранный address перед соединением. Соединение выполняется с проверенным IP при сохранении исходного TLS peer name/Host.
- Connect timeout, read timeout и максимальный размер response задаются отдельно. Лимит применяется во время чтения, включая chunked body.
- Trusted proxy CIDR должны иметь корректный prefix и канонический network address. Forwarded IP учитывается только от доверенной цепочки; невалидный address никогда не становится client IP.
- LDAP в production требует allowlisted host и TLS/StartTLS. Certificate, SAN/hostname и CA options задаются на конкретном connection, а network timeout ограничен.
- SMTP с credentials требует STARTTLS. Сервер должен объявить STARTTLS, а после TLS — AUTH LOGIN. TLS проверяет certificate/hostname; UTF-8 Subject кодируется по RFC 2047, body — quoted-printable. Remote response и секретные payload не журналируются.

## Сообщение об уязвимости

Не публикуйте secrets или эксплуатационные данные в issue. Передавайте отчёт владельцу deployment по закрытому каналу вместе с затронутой версией, воспроизводимым сценарием и оценкой влияния.
