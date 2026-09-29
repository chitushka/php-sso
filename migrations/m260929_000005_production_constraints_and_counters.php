<?php

use yii\db\Migration;

class m260929_000005_production_constraints_and_counters extends Migration
{
    public function safeUp()
    {
        $this->db->pdo->exec(<<<'SQL'
ALTER TABLE users ADD CONSTRAINT chk_users_status CHECK (status IN ('active','pending','blocked','deleted'));
ALTER TABLE users ADD CONSTRAINT chk_users_source CHECK (source IN ('local','ldap','federated'));
ALTER TABLE users ADD CONSTRAINT chk_users_email CHECK (length(email) BETWEEN 3 AND 320 AND position('@' IN email) > 1);
ALTER TABLE users ADD CONSTRAINT chk_users_username_length CHECK (length(username) BETWEEN 1 AND 255);
ALTER TABLE oauth_clients ADD CONSTRAINT chk_oauth_client_id_length CHECK (length(client_id) BETWEEN 1 AND 255);
ALTER TABLE refresh_tokens ADD CONSTRAINT fk_refresh_tokens_client FOREIGN KEY (client_id) REFERENCES oauth_clients(client_id) ON DELETE CASCADE;
ALTER TABLE user_consents ADD CONSTRAINT fk_user_consents_client FOREIGN KEY (client_id) REFERENCES oauth_clients(client_id) ON DELETE CASCADE;

CREATE INDEX idx_sessions_cleanup ON sessions(expires_at, revoked_at);
CREATE INDEX idx_oauth_codes_cleanup ON oauth_authorization_codes(expires_at, used_at);
CREATE INDEX idx_refresh_tokens_cleanup ON refresh_tokens(expires_at, revoked_at);
CREATE INDEX idx_one_time_tokens_cleanup ON one_time_tokens(expires_at, used_at);
CREATE INDEX idx_login_attempts_cleanup ON login_attempts(updated_at);
CREATE INDEX idx_audit_logs_cleanup ON audit_logs(created_at);

CREATE TABLE rate_limit_buckets (
    bucket_key TEXT PRIMARY KEY,
    tokens DOUBLE PRECISION NOT NULL,
    updated_at DOUBLE PRECISION NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    last_allowed BOOLEAN NOT NULL
);
CREATE INDEX idx_rate_limit_buckets_expiry ON rate_limit_buckets(expires_at);

CREATE TABLE metric_counters (
    metric_key TEXT PRIMARY KEY,
    metric_value DOUBLE PRECISION NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);
        return true;
    }

    public function safeDown()
    {
        $this->dropTable('metric_counters');
        $this->dropTable('rate_limit_buckets');
        $this->db->pdo->exec(<<<'SQL'
DROP INDEX IF EXISTS idx_audit_logs_cleanup;
DROP INDEX IF EXISTS idx_login_attempts_cleanup;
DROP INDEX IF EXISTS idx_one_time_tokens_cleanup;
DROP INDEX IF EXISTS idx_refresh_tokens_cleanup;
DROP INDEX IF EXISTS idx_oauth_codes_cleanup;
DROP INDEX IF EXISTS idx_sessions_cleanup;
ALTER TABLE user_consents DROP CONSTRAINT IF EXISTS fk_user_consents_client;
ALTER TABLE refresh_tokens DROP CONSTRAINT IF EXISTS fk_refresh_tokens_client;
ALTER TABLE oauth_clients DROP CONSTRAINT IF EXISTS chk_oauth_client_id_length;
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_username_length;
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_email;
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_source;
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_status;
SQL);
        return true;
    }
}
