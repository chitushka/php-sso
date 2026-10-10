<?php

namespace tests\Integration;

use app\services\RbacService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;
use yii\db\Transaction;

class AdminListServiceTest extends TestCase
{
    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        if (Yii::$app !== null && !Yii::$app instanceof Application) {
            Yii::$app = null;
        }
        if (Yii::$app === null) {
            new Application(require dirname(__DIR__, 2) . '/config/console.php');
        }
        $this->transaction = Yii::$app->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->transaction->isActive) {
            $this->transaction->rollBack();
        }
        parent::tearDown();
    }

    public function testAdminListsHaveStableLimitsOffsetsAndTextFiltering(): void
    {
        for ($index = 0; $index < 55; $index++) {
            Yii::$app->db->createCommand()->insert('groups', [
                'code' => sprintf('pagination-probe-%02d', $index),
                'name' => sprintf('Pagination probe %02d', $index),
            ])->execute();
        }

        $service = new RbacService();
        $first = $service->listGroups(['q' => 'pagination-probe', 'limit' => 50, 'offset' => 0]);
        $second = $service->listGroups(['q' => 'pagination-probe', 'limit' => 50, 'offset' => 50]);

        $this->assertCount(50, $first);
        $this->assertCount(5, $second);
        $this->assertSame('pagination-probe-00', $first[0]['code']);
        $this->assertSame('pagination-probe-50', $second[0]['code']);
    }
}
