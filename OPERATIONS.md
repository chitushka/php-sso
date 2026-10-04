# Эксплуатация SSO

## Наблюдаемость

Приложение пишет однострочные JSON-события в stderr. Каждый HTTP-ответ содержит
`X-Request-ID`; безопасный входящий идентификатор сохраняется, иначе создаётся новый.
Секретные поля и типичные токены маскируются логгером.

`GET /metrics` доступен только из сетей `SSO_METRICS_ALLOWED_CIDRS` или с bearer-токеном
`SSO_METRICS_BEARER_TOKEN`. В production должен быть настроен хотя бы один механизм.
Метрики содержат HTTP latency histogram, результаты auth, rate-limit, SMTP, LDAP,
federated provider и cleanup. Label values ограничены заранее определёнными значениями
и нормализованными маршрутами.

Рекомендуемые SLO за скользящие 30 дней:

- доступность интерактивного login и token endpoint — 99,9%;
- p95 latency login/token без времени внешнего IdP — не более 500 мс;
- не менее 99% запланированных cleanup завершаются успешно;
- RPO PostgreSQL — 24 часа, RTO — 4 часа.

Минимальные alert rules:

- доля HTTP 5xx более 1% в течение 10 минут;
- p95 `sso_http_request_duration_seconds` более 1 секунды в течение 15 минут;
- рост `sso_events_total` с outcome `failure` для auth/LDAP/provider/mail;
- рост rate-limit `blocked` относительно обычного профиля;
- `time() - sso_cleanup_last_success_timestamp_seconds > 7200`;
- `/health/ready` недоступен три последовательных опроса.

## Health и cleanup

`/health/live` подтверждает работу процесса. `/health/ready` проверяет PostgreSQL,
локальное шифрование/расшифрование и подпись/проверку OIDC без внешних запросов.
Compose profile `operations` запускает `cleanup-scheduler`; он выполняет cleanup сразу,
затем каждые `SSO_CLEANUP_INTERVAL` секунд. Его healthcheck требует успешный запуск за
последние два часа. Ручной запуск: `make cleanup`.

## Резервное копирование и восстановление

Ежедневно создаётся зашифрованный логический backup PostgreSQL; рекомендуемое хранение —
35 ежедневных и 12 ежемесячных копий в отдельном fault domain. Доступ ограничивается
ролью восстановления. Не реже раза в квартал backup восстанавливается в изолированную
БД, выполняются миграции, `cutover/check` и smoke checks. Результат и фактические RPO/RTO
фиксируются в журнале эксплуатации. Compose `rollback-rehearsal` предназначен только для
локальной репетиции и не заменяет восстановление production backup.

## Секреты и ключи

Секреты передаются через Docker/Kubernetes secret files с переменными `*_FILE`; файлы
монтируются read-only и доступны только UID приложения. JWT secret меняется не реже раза
в 90 дней с учётом максимального TTL выпущенных токенов. OIDC signing key ротируется
раз в 90 дней с периодом публикации старого public key не короче TTL токенов. Encryption
key ротируется раз в год либо немедленно при подозрении на компрометацию; перед применением
обязательны backup и dry-run re-encryption. Пароли БД/SMTP/LDAP меняются раз в 90 дней.

Аварийная ротация требует отзыва сессий и refresh tokens, замены ключей, проверки аудита,
readiness и всех protocol smoke tests.

## Supply chain

Production и test Docker targets разделены; runtime работает как `www-data` с read-only
root filesystem и не содержит Composer, тестов или dev dependencies. Dependabot создаёт
контролируемые еженедельные обновления Composer, base images и CI actions. CI генерирует
SPDX SBOM, блокирует HIGH/CRITICAL уязвимости production image и выполняет
`composer audit --locked`.

Runtime stage выполняет установку доступных Debian security updates при каждой свежей
сборке. Поэтому production image для релиза необходимо собирать с `--pull`, после чего
обязательный CI scan должен относиться к полученному image digest, а не к ранее
закэшированному локальному образу.
