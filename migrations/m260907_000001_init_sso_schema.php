<?php

use yii\db\Migration;

class m260907_000001_init_sso_schema extends Migration
{
    public function safeUp()
    {
        // Базовая схема новой установки SSO.
        $this->db->pdo->exec(<<<'SQL'
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE ldap_providers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name TEXT NOT NULL UNIQUE,
    host TEXT NOT NULL,
    port INTEGER NOT NULL DEFAULT 389 CHECK (port > 0 AND port <= 65535),
    use_tls BOOLEAN NOT NULL DEFAULT false,
    start_tls BOOLEAN NOT NULL DEFAULT false,
    bind_dn TEXT NOT NULL,
    bind_password TEXT NOT NULL,
    base_dn TEXT NOT NULL,
    user_filter TEXT NOT NULL DEFAULT '(&(objectClass=user)(sAMAccountName={username}))',
    username_attribute TEXT NOT NULL DEFAULT 'sAMAccountName',
    email_attribute TEXT NOT NULL DEFAULT 'mail',
    display_name_attribute TEXT NOT NULL DEFAULT 'displayName',
    group_attribute TEXT NOT NULL DEFAULT 'memberOf',
    enabled BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (NOT (use_tls AND start_tls))
);

CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL,
    password_hash TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    source TEXT NOT NULL DEFAULT 'local',
    ldap_provider_id UUID REFERENCES ldap_providers(id) ON DELETE SET NULL,
    ldap_dn TEXT,
    first_name TEXT NOT NULL DEFAULT '',
    last_name TEXT NOT NULL DEFAULT '',
    attributes JSONB NOT NULL DEFAULT '{}',
    email_verified BOOLEAN NOT NULL DEFAULT false,
    mfa_enabled BOOLEAN NOT NULL DEFAULT false,
    mfa_secret TEXT NOT NULL DEFAULT '',
    mfa_last_used_counter BIGINT NOT NULL DEFAULT 0,
    tokens_invalid_before TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_login_at TIMESTAMPTZ
);

CREATE TABLE sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    ip TEXT,
    user_agent TEXT,
    expires_at TIMESTAMPTZ NOT NULL,
    revoked_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE audit_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    actor_user_id UUID,
    action TEXT NOT NULL,
    target_type TEXT NOT NULL,
    target_id TEXT NOT NULL,
    ip TEXT,
    user_agent TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE oauth_clients (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    client_id TEXT NOT NULL UNIQUE,
    client_secret_hash TEXT,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('confidential','public')),
    redirect_uris TEXT[] NOT NULL DEFAULT '{}',
    allowed_scopes TEXT[] NOT NULL DEFAULT '{}',
    enabled BOOLEAN NOT NULL DEFAULT true,
    post_logout_redirect_uris TEXT[] NOT NULL DEFAULT '{}',
    backchannel_logout_uri TEXT NOT NULL DEFAULT '',
    skip_consent BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE oauth_authorization_codes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code_hash TEXT NOT NULL UNIQUE,
    client_id TEXT NOT NULL REFERENCES oauth_clients(client_id) ON DELETE CASCADE,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    redirect_uri TEXT NOT NULL,
    scope TEXT NOT NULL,
    code_challenge TEXT,
    code_challenge_method TEXT,
    nonce TEXT,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE oidc_signing_keys (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    kid TEXT NOT NULL UNIQUE,
    alg TEXT NOT NULL,
    private_key_pem TEXT NOT NULL,
    public_key_pem TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('active','retiring','retired')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at TIMESTAMPTZ
);

CREATE TABLE refresh_tokens (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    token_hash TEXT NOT NULL UNIQUE,
    family_id UUID NOT NULL,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    client_id TEXT NOT NULL,
    scope TEXT NOT NULL DEFAULT '',
    expires_at TIMESTAMPTZ NOT NULL,
    rotated_at TIMESTAMPTZ,
    revoked_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE login_attempts (
    username TEXT NOT NULL,
    ip TEXT NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    locked_until TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (username, ip)
);

CREATE TABLE roles (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE permissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code TEXT NOT NULL UNIQUE,
    resource TEXT NOT NULL,
    action TEXT NOT NULL,
    description TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE(resource, action)
);

CREATE TABLE role_permissions (
    role_id UUID NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id UUID NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_roles (
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id UUID NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE user_consents (
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    client_id TEXT NOT NULL,
    scopes TEXT NOT NULL DEFAULT '',
    granted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, client_id)
);

CREATE TABLE one_time_tokens (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    purpose TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE mfa_recovery_codes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash TEXT NOT NULL,
    used_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE groups (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE group_roles (
    group_id UUID NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    role_id UUID NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (group_id, role_id)
);

CREATE TABLE user_groups (
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    group_id UUID NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    source TEXT NOT NULL DEFAULT 'manual',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, group_id)
);

CREATE TABLE ldap_group_mappings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    provider_id UUID NOT NULL REFERENCES ldap_providers(id) ON DELETE CASCADE,
    ldap_group TEXT NOT NULL,
    group_id UUID NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (provider_id, ldap_group, group_id)
);

CREATE TABLE identity_providers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'oidc',
    client_id TEXT NOT NULL,
    client_secret TEXT NOT NULL DEFAULT '',
    authorize_url TEXT NOT NULL DEFAULT '',
    token_url TEXT NOT NULL DEFAULT '',
    userinfo_url TEXT NOT NULL DEFAULT '',
    scopes TEXT NOT NULL DEFAULT 'openid profile email',
    enabled BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE federated_identities (
    provider_id UUID NOT NULL REFERENCES identity_providers(id) ON DELETE CASCADE,
    external_subject TEXT NOT NULL,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    email TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (provider_id, external_subject)
);

CREATE INDEX idx_ldap_providers_enabled ON ldap_providers(enabled);
CREATE INDEX idx_users_ldap_provider_id ON users(ldap_provider_id);
CREATE INDEX idx_sessions_user_id ON sessions(user_id);
CREATE INDEX idx_oidc_signing_keys_status ON oidc_signing_keys(status);
CREATE INDEX idx_refresh_tokens_family_id ON refresh_tokens(family_id);
CREATE INDEX idx_refresh_tokens_user_id ON refresh_tokens(user_id);
CREATE INDEX idx_role_permissions_permission_id ON role_permissions(permission_id);
CREATE INDEX idx_user_roles_role_id ON user_roles(role_id);
CREATE INDEX idx_one_time_tokens_user ON one_time_tokens(user_id, purpose);
CREATE INDEX idx_mfa_recovery_codes_user ON mfa_recovery_codes(user_id);
CREATE INDEX idx_user_groups_group ON user_groups(group_id);
CREATE INDEX idx_federated_identities_user ON federated_identities(user_id);

INSERT INTO roles(code, name, description)
VALUES
    ('admin', 'Administrator', 'Full system administrator'),
    ('user', 'User', 'Regular authenticated user')
ON CONFLICT (code) DO NOTHING;

INSERT INTO permissions(code, resource, action, description)
VALUES
    ('users:read', 'users', 'read', 'Read users'),
    ('users:create', 'users', 'create', 'Create users'),
    ('users:update', 'users', 'update', 'Update users'),
    ('users:delete', 'users', 'delete', 'Delete users'),
    ('roles:read', 'roles', 'read', 'Read roles'),
    ('roles:create', 'roles', 'create', 'Create roles'),
    ('roles:update', 'roles', 'update', 'Update roles'),
    ('roles:delete', 'roles', 'delete', 'Delete roles'),
    ('roles:assign', 'roles', 'assign', 'Assign roles'),
    ('permissions:read', 'permissions', 'read', 'Read permissions'),
    ('permissions:assign', 'permissions', 'assign', 'Assign permissions'),
    ('ldap:read', 'ldap', 'read', 'Read LDAP providers'),
    ('ldap:create', 'ldap', 'create', 'Create LDAP providers'),
    ('ldap:update', 'ldap', 'update', 'Update LDAP providers'),
    ('ldap:delete', 'ldap', 'delete', 'Delete LDAP providers'),
    ('ldap:test', 'ldap', 'test', 'Test LDAP providers'),
    ('oauth_clients:read', 'oauth_clients', 'read', 'Read OAuth clients'),
    ('oauth_clients:create', 'oauth_clients', 'create', 'Create OAuth clients'),
    ('oauth_clients:update', 'oauth_clients', 'update', 'Update OAuth clients'),
    ('oauth_clients:delete', 'oauth_clients', 'delete', 'Delete OAuth clients'),
    ('audit:read', 'audit', 'read', 'Read audit logs'),
    ('groups:read', 'groups', 'read', 'Read groups'),
    ('groups:create', 'groups', 'create', 'Create groups'),
    ('groups:update', 'groups', 'update', 'Update groups'),
    ('groups:delete', 'groups', 'delete', 'Delete groups'),
    ('groups:assign', 'groups', 'assign', 'Assign groups and group roles'),
    ('identity_providers:read', 'identity_providers', 'read', 'Read identity providers'),
    ('identity_providers:create', 'identity_providers', 'create', 'Create identity providers'),
    ('identity_providers:update', 'identity_providers', 'update', 'Update identity providers'),
    ('identity_providers:delete', 'identity_providers', 'delete', 'Delete identity providers')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'admin'
ON CONFLICT DO NOTHING;

INSERT INTO role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN ('users:read')
WHERE r.code = 'user'
ON CONFLICT DO NOTHING;
SQL);
    }

    public function safeDown()
    {
        $this->db->pdo->exec(<<<'SQL'
DROP TABLE IF EXISTS federated_identities CASCADE;
DROP TABLE IF EXISTS identity_providers CASCADE;
DROP TABLE IF EXISTS ldap_group_mappings CASCADE;
DROP TABLE IF EXISTS user_groups CASCADE;
DROP TABLE IF EXISTS group_roles CASCADE;
DROP TABLE IF EXISTS groups CASCADE;
DROP TABLE IF EXISTS mfa_recovery_codes CASCADE;
DROP TABLE IF EXISTS one_time_tokens CASCADE;
DROP TABLE IF EXISTS user_consents CASCADE;
DROP TABLE IF EXISTS user_roles CASCADE;
DROP TABLE IF EXISTS role_permissions CASCADE;
DROP TABLE IF EXISTS permissions CASCADE;
DROP TABLE IF EXISTS roles CASCADE;
DROP TABLE IF EXISTS login_attempts CASCADE;
DROP TABLE IF EXISTS refresh_tokens CASCADE;
DROP TABLE IF EXISTS oidc_signing_keys CASCADE;
DROP TABLE IF EXISTS oauth_authorization_codes CASCADE;
DROP TABLE IF EXISTS oauth_clients CASCADE;
DROP TABLE IF EXISTS audit_logs CASCADE;
DROP TABLE IF EXISTS sessions CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS ldap_providers CASCADE;
SQL);
    }
}
