<?php

namespace app\controllers;

use app\services\FederatedIdentityService;
use yii\base\Module;

final class FederatedIdentitiesController extends ApiController
{
    public function __construct(
        string $id,
        Module $module,
        private FederatedIdentityService $identities,
        array $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public function actionIndex(string $userID): array
    {
        $auth = $this->requirePermission('federated_identities', 'read');
        if (is_array($auth)) {
            return $auth;
        }
        if (!$this->isUuid($userID)) {
            return $this->fail(400, 'invalid user id');
        }

        return $this->respond($this->identities->listForUser($this->canonicalUuid($userID)));
    }

    public function actionDelete(string $userID): array
    {
        $auth = $this->requirePermission('federated_identities', 'delete');
        if (is_array($auth)) {
            return $auth;
        }
        if (!$this->isUuid($userID)) {
            return $this->fail(400, 'invalid user id');
        }
        $body = $this->body(
            ['provider_id', 'external_subject'],
            ['provider_id' => 'uuid', 'external_subject' => 'string']
        );
        if (empty($body['provider_id']) || trim((string)($body['external_subject'] ?? '')) === '') {
            return $this->fail(400, 'provider_id and external_subject are required');
        }

        return $this->respond($this->identities->unlink(
            $this->canonicalUuid($userID),
            (string)$body['provider_id'],
            (string)$body['external_subject'],
            (string)$auth->id
        ));
    }
}
