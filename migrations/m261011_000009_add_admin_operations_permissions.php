<?php

use yii\db\Migration;

final class m261011_000009_add_admin_operations_permissions extends Migration
{
    public function safeUp(): void
    {
        $this->execute(<<<'SQL'
INSERT INTO permissions(code, resource, action, description) VALUES
    ('federated_identities:read', 'federated_identities', 'read', 'Read federated identity links'),
    ('federated_identities:delete', 'federated_identities', 'delete', 'Delete federated identity links'),
    ('signing_keys:read', 'signing_keys', 'read', 'Read signing key metadata'),
    ('signing_keys:rotate', 'signing_keys', 'rotate', 'Rotate signing keys'),
    ('operations:read', 'operations', 'read', 'Read operational status')
ON CONFLICT (code) DO NOTHING;
SQL);
        $this->execute(<<<'SQL'
INSERT INTO role_permissions(role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'admin'
  AND p.code IN (
      'federated_identities:read',
      'federated_identities:delete',
      'signing_keys:read',
      'signing_keys:rotate',
      'operations:read'
  )
ON CONFLICT DO NOTHING;
SQL);
    }

    public function safeDown(): void
    {
        $this->delete('permissions', ['code' => [
            'federated_identities:read',
            'federated_identities:delete',
            'signing_keys:read',
            'signing_keys:rotate',
            'operations:read',
        ]]);
    }
}
