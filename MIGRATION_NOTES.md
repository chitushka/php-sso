# Deployment and database upgrade runbook

Документ описывает deployment новой версии SSO PHP, применение schema migrations, backup, smoke-check и восстановление. Он не является сравнением с другой реализацией и не задаёт продуктовый API.

## До окна изменений

1. Зафиксировать image digest и версию приложения.
2. Проверить production secrets, HTTPS issuer/audience, secure cookies, trusted proxies, LDAP/SMTP CA и PostgreSQL `verify-full` или согласованный TLS mode.
3. Выполнить read-only readiness check:

```bash
make cutover-check
```

4. Выполнить dry-run ротации/перешифрования, если меняется `SSO_ENCRYPTION_KEY`.
5. Создать проверяемый PostgreSQL backup и сохранить его вне Compose volume.
6. Выполнить restore rehearsal:

```bash
make cutover-rehearse-rollback
```

Исторический префикс имён команд будет заменён нейтральным deployment/readiness naming в рамках `PLAN.md`.

## Применение миграций

Миграции запускаются отдельным operations job, а не application entrypoint:

```bash
docker compose --profile operations run --rm migrate
```

Перед применением каждой новой миграции должны быть определены:

- совместимость с текущей версией приложения;
- lock/statement timeout и влияние на большие таблицы;
- необходимость backfill и его batch strategy;
- возможность rollback или обязательное restore из backup;
- проверка результата и expected schema version.

Baseline migrations с `IF NOT EXISTS` не считаются полноценной проверкой schema drift. До их переработки всегда выполняйте readiness/schema inventory и restore rehearsal.

Миграция `m261004_000006_normalize_user_email` нормализует email и создаёт уникальный индекс `lower(email)`. При существующих дублях после `trim + lowercase` она останавливается до изменения данных. Такие записи нужно разобрать вручную по владельцам учётных записей, затем повторить migration job; автоматически объединять пользователей запрещено.

## Maintenance window

Для операций, требующих отсутствия записи:

```bash
make cutover-maintenance-on
```

Maintenance mode оставляет доступным только liveness и блокирует остальные HTTP requests. После включения убедитесь, что фоновые writers и старые application instances остановлены, затем снимите финальный backup и примените миграции.

После успешной проверки:

```bash
make cutover-maintenance-off
```

## Ротация encryption key

Передавайте старый и новый ключи через secret manager/environment только maintenance process. Не помещайте значения в историю shell или repository.

```bash
make cutover-rotate-key-dry-run
make cutover-rotate-key
```

Во время apply не должно быть других writers. После commit атомарно обновите `SSO_ENCRYPTION_KEY` для всех instances, перезапустите их и повторите readiness/smoke. Не удаляйте старый ключ до подтверждённого backup и завершённой проверки.

## Post-deployment checks

Сначала выполните read-only public smoke:

```bash
make cutover-smoke
```

Затем authenticated smoke выделенной тестовой учётной записью и зарегистрированным клиентом:

```bash
make cutover-smoke-authenticated
```

Проверить вручную/мониторингом:

- liveness/readiness и точный issuer в Discovery;
- пригодный активный RS256 JWKS key;
- local login/MFA и `/auth/me`;
- Authorization Code + PKCE, UserInfo, refresh rotation и logout;
- LDAP/federated login, если они используются в окружении;
- SMTP delivery без token/secret leakage в logs;
- error rate, latency, DB connections, rate-limit и cleanup health.

## Restore

Restore выбирается вместо down migration, если изменение данных необратимо или rollback-код не проверен.

1. Включить maintenance и остановить все writers.
2. Сохранить аварийный dump текущего состояния отдельно.
3. Восстановить последний проверенный backup в новую/очищенную БД по утверждённой процедуре.
4. Вернуть совместимый image и набор secrets/configuration.
5. Проверить schema version, row counts, extensions, seed permissions и расшифровку secrets.
6. Запустить public и authenticated smoke.
7. Только после успешной проверки снять maintenance и возобновить traffic.

## Обязательные артефакты релиза

- image digest и application version;
- список миграций и оценка lock/backfill;
- backup location, checksum и restore rehearsal result;
- результаты lint/unit/integration/security audits;
- smoke results и время переключения;
- владелец решения о продолжении/restore;
- changelog API/config/schema изменений.
