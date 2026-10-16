<?php

use app\services\CryptoService;
use app\services\DatabaseInvariantService;
use yii\db\Migration;

class m261016_000012_enforce_security_model_invariants extends Migration
{
    public function safeUp()
    {
        $service = new DatabaseInvariantService($this->db, new CryptoService());
        $diagnosis = $service->diagnose();
        echo 'DB invariant drift before repair: ' . json_encode($diagnosis['counts']) . "\n";
        $service->repair();

        $this->db->pdo->exec(<<<'SQL'
ALTER TABLE users ADD CONSTRAINT chk_users_auth_source CHECK (
    (source = 'local' AND NULLIF(btrim(password_hash), '') IS NOT NULL AND ldap_provider_id IS NULL AND ldap_dn IS NULL)
    OR (source = 'ldap' AND password_hash IS NULL AND ldap_provider_id IS NOT NULL AND NULLIF(btrim(ldap_dn), '') IS NOT NULL)
    OR (source = 'federated' AND password_hash IS NULL AND ldap_provider_id IS NULL AND ldap_dn IS NULL)
);
ALTER TABLE oauth_clients ADD CONSTRAINT chk_oauth_clients_secret CHECK (
    (type = 'confidential' AND NULLIF(btrim(client_secret_hash), '') IS NOT NULL)
    OR (type = 'public' AND client_secret_hash IS NULL)
);
ALTER TABLE oidc_signing_keys ADD CONSTRAINT chk_oidc_signing_keys_alg CHECK (alg = 'RS256');
ALTER TABLE oidc_signing_keys ADD CONSTRAINT chk_oidc_signing_keys_lifecycle CHECK (
    (status = 'active' AND expires_at IS NULL)
    OR (status IN ('retiring', 'retired') AND expires_at IS NOT NULL)
);
CREATE UNIQUE INDEX ux_oidc_signing_keys_one_active
    ON oidc_signing_keys ((status)) WHERE status = 'active';

CREATE FUNCTION enforce_federated_user_row_link() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.source = 'federated'
       AND NOT EXISTS (SELECT 1 FROM federated_identities WHERE user_id = NEW.id) THEN
        RAISE EXCEPTION 'federated user % must have at least one identity link', NEW.id
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END;
$$;

CREATE FUNCTION enforce_federated_identity_link() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM users WHERE id = OLD.user_id AND source = 'federated')
       AND NOT EXISTS (SELECT 1 FROM federated_identities WHERE user_id = OLD.user_id) THEN
        RAISE EXCEPTION 'federated user % must have at least one identity link', OLD.user_id
            USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.user_id <> OLD.user_id THEN
        IF EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND source = 'federated')
           AND NOT EXISTS (SELECT 1 FROM federated_identities WHERE user_id = NEW.user_id) THEN
            RAISE EXCEPTION 'federated user % must have at least one identity link', NEW.user_id
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER trg_users_federated_link
AFTER INSERT OR UPDATE OF source ON users
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION enforce_federated_user_row_link();
CREATE CONSTRAINT TRIGGER trg_federated_identities_user_link
AFTER DELETE OR UPDATE OF user_id ON federated_identities
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION enforce_federated_identity_link();
SQL);

        return true;
    }

    public function safeDown()
    {
        $this->db->pdo->exec(<<<'SQL'
DROP TRIGGER IF EXISTS trg_federated_identities_user_link ON federated_identities;
DROP TRIGGER IF EXISTS trg_users_federated_link ON users;
DROP FUNCTION IF EXISTS enforce_federated_identity_link();
DROP FUNCTION IF EXISTS enforce_federated_user_row_link();
DROP INDEX IF EXISTS ux_oidc_signing_keys_one_active;
ALTER TABLE oidc_signing_keys DROP CONSTRAINT IF EXISTS chk_oidc_signing_keys_lifecycle;
ALTER TABLE oidc_signing_keys DROP CONSTRAINT IF EXISTS chk_oidc_signing_keys_alg;
ALTER TABLE oauth_clients DROP CONSTRAINT IF EXISTS chk_oauth_clients_secret;
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_auth_source;
SQL);

        return true;
    }
}
