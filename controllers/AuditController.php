<?php

namespace app\controllers;

use app\models\AuditLog;
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
            $query->andWhere(['actor_user_id' => $request->get('actor')]);
        }
        if ($request->get('action')) {
            $query->andWhere(['action' => $request->get('action')]);
        }
        if ($request->get('from')) {
            $query->andWhere(['>=', 'created_at', $request->get('from')]);
        }
        if ($request->get('to')) {
            $query->andWhere(['<=', 'created_at', $request->get('to')]);
        }

        $limit = min(max((int)$request->get('limit', 50), 1), 200);
        $offset = max((int)$request->get('offset', 0), 0);

        return $query->limit($limit)->offset($offset)->asArray()->all();
    }
}
