<?php

namespace app\controllers;

use app\models\AuditLog;
use DateTimeImmutable;
use DateTimeInterface;
use Yii;

class AuditController extends ApiController
{
    public function actionIndex(): array
    {
        $auth = $this->requirePermission('audit', 'read');
        if (is_array($auth)) {
            return $auth;
        }

        $query = AuditLog::find()->orderBy(['created_at' => SORT_DESC]);
        $request = Yii::$app->request;

        if ($request->get('actor')) {
            $actor = (string)$request->get('actor');
            if (!preg_match('/^[0-9a-fA-F-]{36}$/', $actor)) {
                return $this->fail(400, 'invalid actor id');
            }
            $query->andWhere(['actor_user_id' => $actor]);
        }
        if ($request->get('action')) {
            $query->andWhere(['action' => $request->get('action')]);
        }
        if ($request->get('from')) {
            $from = $this->parseRfc3339((string)$request->get('from'));
            if ($from === null) {
                return $this->fail(400, 'invalid from, expected RFC3339');
            }
            $query->andWhere(['>=', 'created_at', $from->format('Y-m-d H:i:sP')]);
        }
        if ($request->get('to')) {
            $to = $this->parseRfc3339((string)$request->get('to'));
            if ($to === null) {
                return $this->fail(400, 'invalid to, expected RFC3339');
            }
            $query->andWhere(['<=', 'created_at', $to->format('Y-m-d H:i:sP')]);
        }

        $limit = min(max((int)$request->get('limit', 50), 1), 200);
        $offset = max((int)$request->get('offset', 0), 0);

        return $query->limit($limit)->offset($offset)->asArray()->all();
    }

    private function parseRfc3339(string $value): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $value);
        return $parsed instanceof DateTimeImmutable ? $parsed : null;
    }
}
