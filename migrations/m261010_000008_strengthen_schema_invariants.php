<?php

use yii\db\Migration;

class m261010_000008_strengthen_schema_invariants extends Migration
{
    public function safeUp()
    {
        $this->db->pdo->exec(<<<'SQL'
ALTER TABLE audit_logs
    ADD CONSTRAINT fk_audit_logs_actor
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL;
UPDATE identity_providers SET type = 'oidc' WHERE type = '';
ALTER TABLE identity_providers
    ADD CONSTRAINT chk_identity_providers_type CHECK (type IN ('oidc'));
ALTER TABLE user_groups
    ADD CONSTRAINT chk_user_groups_source CHECK (source IN ('manual','ldap'));
ALTER TABLE one_time_tokens
    ADD CONSTRAINT chk_one_time_tokens_purpose
    CHECK (purpose IN ('mfa_login','password_reset','email_verify'));
ALTER TABLE oauth_authorization_codes
    ADD CONSTRAINT chk_oauth_codes_challenge_method
    CHECK (code_challenge_method IS NULL OR code_challenge_method IN ('S256'));
ALTER TABLE login_attempts
    ADD CONSTRAINT chk_login_attempts_nonnegative CHECK (attempts >= 0);
ALTER TABLE rate_limit_buckets
    ADD CONSTRAINT chk_rate_limit_tokens_nonnegative CHECK (tokens >= 0);
ALTER TABLE metric_counters
    ADD CONSTRAINT chk_metric_counters_nonnegative CHECK (metric_value >= 0);
ALTER TABLE mfa_recovery_codes
    ADD CONSTRAINT ux_mfa_recovery_codes_user_code UNIQUE (user_id, code_hash);

CREATE INDEX idx_users_admin_list ON users(created_at DESC, id DESC);
CREATE INDEX idx_oauth_clients_admin_list ON oauth_clients(created_at DESC, id DESC);
CREATE INDEX idx_ldap_providers_admin_list ON ldap_providers(created_at DESC, id DESC);
CREATE INDEX idx_ldap_group_mappings_admin_list ON ldap_group_mappings(provider_id, created_at DESC, id DESC);
CREATE INDEX idx_audit_logs_admin_list ON audit_logs(created_at DESC, id DESC);
CREATE INDEX idx_audit_logs_actor_list ON audit_logs(actor_user_id, created_at DESC, id DESC);
CREATE INDEX idx_audit_logs_action_list ON audit_logs(action, created_at DESC, id DESC);
SQL);

        return true;
    }

    public function safeDown()
    {
        $this->db->pdo->exec(<<<'SQL'
DROP INDEX idx_audit_logs_action_list;
DROP INDEX idx_audit_logs_actor_list;
DROP INDEX idx_audit_logs_admin_list;
DROP INDEX idx_ldap_group_mappings_admin_list;
DROP INDEX idx_ldap_providers_admin_list;
DROP INDEX idx_oauth_clients_admin_list;
DROP INDEX idx_users_admin_list;
ALTER TABLE mfa_recovery_codes DROP CONSTRAINT ux_mfa_recovery_codes_user_code;
ALTER TABLE metric_counters DROP CONSTRAINT chk_metric_counters_nonnegative;
ALTER TABLE rate_limit_buckets DROP CONSTRAINT chk_rate_limit_tokens_nonnegative;
ALTER TABLE login_attempts DROP CONSTRAINT chk_login_attempts_nonnegative;
ALTER TABLE oauth_authorization_codes DROP CONSTRAINT chk_oauth_codes_challenge_method;
ALTER TABLE one_time_tokens DROP CONSTRAINT chk_one_time_tokens_purpose;
ALTER TABLE user_groups DROP CONSTRAINT chk_user_groups_source;
ALTER TABLE identity_providers DROP CONSTRAINT chk_identity_providers_type;
ALTER TABLE audit_logs DROP CONSTRAINT fk_audit_logs_actor;
SQL);

        return true;
    }
}
