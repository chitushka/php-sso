<?php

namespace app\controllers;

use app\models\AuditLog;
use DateTimeImmutable;
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
            if (!$this->isUuid($actor)) {
                return $this->fail(400, 'invalid actor id');
            }
            $actor = $this->canonicalUuid($actor);
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

        $limit = $this->parseQueryInteger((string)$request->get('limit', ''));
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $offset = $this->parseQueryInteger((string)$request->get('offset', ''));
        if ($offset < 0) {
            $offset = 0;
        }

        return array_map(static function (AuditLog $log): array {
            return $log->publicData();
        }, $query->limit($limit)->offset($offset)->all());
    }

    private function parseRfc3339(string $value): ?DateTimeImmutable
    {
        if (
            !preg_match(
                '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})$/D',
                $value,
                $matches
            )
        ) {
            return null;
        }

        $fraction = $matches[2] ?? '';
        $zone = $matches[3] === 'Z' ? '+00:00' : $matches[3];
        $normalized = $matches[1] . $zone;
        $format = '!Y-m-d\TH:i:sP';
        if ($fraction !== '') {
            $normalized = $matches[1] . '.' . substr(str_pad($fraction, 6, '0'), 0, 6) . $zone;
            $format = '!Y-m-d\TH:i:s.uP';
        }

        $parsed = DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed;
    }
}
