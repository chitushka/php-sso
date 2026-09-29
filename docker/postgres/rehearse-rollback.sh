#!/bin/sh

set -eu

source_db="${SOURCE_DB:-}"
case "$source_db" in
    ''|postgres|template0|template1|*[!a-zA-Z0-9_]* )
        echo "Unsafe or empty SOURCE_DB" >&2
        exit 1
        ;;
esac

rehearsal_db="sso_rollback_rehearsal_$(date +%s)_$$"
dump_path="/tmp/${rehearsal_db}.dump"

cleanup() {
    # Временная БД и dump удаляются при любом исходе репетиции.
    dropdb --if-exists --force "$rehearsal_db" >/dev/null 2>&1 || true
    rm -f "$dump_path"
}
trap cleanup EXIT HUP INT TERM

business_counts_sql="
SELECT concat_ws('|',
    (SELECT count(*) FROM users),
    (SELECT count(*) FROM sessions),
    (SELECT count(*) FROM refresh_tokens),
    (SELECT count(*) FROM oauth_clients),
    (SELECT count(*) FROM oauth_authorization_codes),
    (SELECT count(*) FROM oidc_signing_keys),
    (SELECT count(*) FROM roles),
    (SELECT count(*) FROM permissions),
    (SELECT count(*) FROM role_permissions),
    (SELECT count(*) FROM user_roles),
    (SELECT count(*) FROM groups),
    (SELECT count(*) FROM group_roles),
    (SELECT count(*) FROM user_groups),
    (SELECT count(*) FROM ldap_providers),
    (SELECT count(*) FROM ldap_group_mappings),
    (SELECT count(*) FROM identity_providers),
    (SELECT count(*) FROM federated_identities),
    (SELECT count(*) FROM user_consents),
    (SELECT count(*) FROM one_time_tokens),
    (SELECT count(*) FROM revoked_access_tokens),
    (SELECT count(*) FROM mfa_recovery_codes)
);"

before_counts="$(psql -XAt --set=ON_ERROR_STOP=1 --dbname="$source_db" --command="$business_counts_sql")"

pg_dump \
    --format=custom \
    --no-owner \
    --no-privileges \
    --serializable-deferrable \
    --file="$dump_path" \
    "$source_db"

after_counts="$(psql -XAt --set=ON_ERROR_STOP=1 --dbname="$source_db" --command="$business_counts_sql")"
if [ "$before_counts" != "$after_counts" ]; then
    echo "Source business row counts changed while the backup was created" >&2
    exit 1
fi

createdb --template=template0 "$rehearsal_db"
pg_restore \
    --exit-on-error \
    --no-owner \
    --no-privileges \
    --dbname="$rehearsal_db" \
    "$dump_path"

missing_schema="$(psql -XAt --set=ON_ERROR_STOP=1 --dbname="$rehearsal_db" <<'SQL'
WITH required(name) AS (
    VALUES
        ('users'), ('sessions'), ('refresh_tokens'), ('oauth_clients'),
        ('oauth_authorization_codes'), ('oidc_signing_keys'), ('roles'),
        ('permissions'), ('role_permissions'), ('user_roles'), ('groups'),
        ('group_roles'), ('user_groups'), ('ldap_providers'),
        ('ldap_group_mappings'), ('identity_providers'),
        ('federated_identities'), ('user_consents'), ('one_time_tokens'),
        ('revoked_access_tokens'), ('mfa_recovery_codes'), ('login_attempts'),
        ('audit_logs'), ('cache')
)
SELECT count(*)
FROM required
WHERE to_regclass('public.' || name) IS NULL;
SQL
)"
if [ "$missing_schema" != "0" ]; then
    echo "Restored database is missing ${missing_schema} critical schema objects" >&2
    exit 1
fi

extension_ok="$(psql -XAt --set=ON_ERROR_STOP=1 --dbname="$rehearsal_db" --command="SELECT count(*) FROM pg_extension WHERE extname = 'pgcrypto'")"
if [ "$extension_ok" != "1" ]; then
    echo "Restored database does not contain pgcrypto" >&2
    exit 1
fi

seed_ok="$(psql -XAt --set=ON_ERROR_STOP=1 --dbname="$rehearsal_db" --command="SELECT (SELECT count(*) FROM roles WHERE code IN ('admin','user')) = 2 AND (SELECT count(*) FROM permissions) > 0")"
if [ "$seed_ok" != "t" ]; then
    echo "Restored database has incomplete seed data" >&2
    exit 1
fi

restored_counts="$(psql -XAt --set=ON_ERROR_STOP=1 --dbname="$rehearsal_db" --command="$business_counts_sql")"
if [ "$before_counts" != "$restored_counts" ]; then
    echo "Restored business row counts differ from the backup source" >&2
    exit 1
fi

secret_formats_sql="
SELECT concat_ws('|',
    count(*) FILTER (WHERE secret LIKE 'enc:v1:%'),
    count(*) FILTER (WHERE secret NOT LIKE 'enc:v1:%')
)
FROM (
    SELECT bind_password AS secret FROM ldap_providers WHERE bind_password <> ''
    UNION ALL
    SELECT client_secret FROM identity_providers WHERE client_secret <> ''
    UNION ALL
    SELECT mfa_secret FROM users WHERE mfa_secret <> ''
) encrypted_secrets;"
source_secret_formats="$(psql -XAt --set=ON_ERROR_STOP=1 --dbname="$source_db" --command="$secret_formats_sql")"
restored_secret_formats="$(psql -XAt --set=ON_ERROR_STOP=1 --dbname="$rehearsal_db" --command="$secret_formats_sql")"
if [ "$source_secret_formats" != "$restored_secret_formats" ]; then
    echo "Restored encrypted-secret format inventory differs from the backup source" >&2
    exit 1
fi
invalid_secret_count="${restored_secret_formats##*|}"
if [ "$invalid_secret_count" != "0" ]; then
    echo "Restored database contains non-enc:v1 secrets" >&2
    exit 1
fi
encrypted_secret_count="${restored_secret_formats%%|*}"

archive_checksum="$(sha256sum "$dump_path" | awk '{print $1}')"
echo "Rollback rehearsal passed: dump restored into an isolated database"
echo "Verified critical schema, pgcrypto, seed data, business row counts and ${encrypted_secret_count} enc:v1 secrets"
echo "Ephemeral archive SHA-256: ${archive_checksum}"
