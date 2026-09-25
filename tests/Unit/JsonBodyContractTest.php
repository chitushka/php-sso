<?php

namespace tests\Unit;

use app\controllers\ApiController;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\web\Application;
use yii\web\BadRequestHttpException;
use yii\web\Request;

class JsonBodyContractTest extends TestCase
{
    /** @var mixed */
    private $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Yii::$app;

        new Application([
            'id' => 'sso-json-contract-unit',
            'basePath' => dirname(__DIR__, 2),
            'components' => [
                'request' => ContractRequest::class,
                'response' => \yii\web\Response::class,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Yii::$app = $this->previousApplication;
        parent::tearDown();
    }

    /**
     * @dataProvider invalidTypedBodyProvider
     */
    public function testRejectsJsonTypesRejectedByGoDecoder(string $raw, array $schema): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('invalid json body');

        $this->decode($raw, $schema);
    }

    public function invalidTypedBodyProvider(): array
    {
        return [
            'empty body' => ['', ['name' => 'string']],
            'number into string' => ['{"name":12}', ['name' => 'string']],
            'string into bool' => ['{"enabled":"false"}', ['enabled' => 'bool']],
            'float into int' => ['{"port":389.0}', ['port' => 'int']],
            'object into string slice' => ['{"scopes":{}}', ['scopes' => 'strings']],
            'mixed string slice' => ['{"scopes":["openid",2]}', ['scopes' => 'strings']],
            'array into string map' => ['{"attributes":[]}', ['attributes' => 'string_map']],
            'typed string map value' => ['{"attributes":{"department":7}}', ['attributes' => 'string_map']],
            'invalid UUID' => ['{"role_id":"not-a-uuid"}', ['role_id' => 'uuid']],
            'invalid RFC3339 time' => ['{"created_at":"2026-02-30T10:00:00Z"}', ['created_at' => 'time']],
        ];
    }

    public function testAcceptsNullLikeGoDecoderAndCanonicalizesUuidForms(): void
    {
        $this->assertSame([], $this->decode('null', ['name' => 'string']));

        $nulls = $this->decode(
            '{"name":null,"enabled":null,"items":null,"attributes":null}',
            ['name' => 'string', 'enabled' => 'bool', 'items' => 'strings', 'attributes' => 'string_map']
        );
        $this->assertSame(
            ['name' => null, 'enabled' => null, 'items' => null, 'attributes' => null],
            $nulls
        );

        $uuid = $this->decode(
            '{"id":"urn:uuid:550E8400-E29B-41D4-A716-446655440000"}',
            ['id' => 'uuid']
        );
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $uuid['id']);

        $caseInsensitive = $this->decode('{"Name":"admin"}', ['name' => 'string']);
        $this->assertSame(['name' => 'admin'], $caseInsensitive);

        $collectionNulls = $this->decode(
            '{"items":["openid",null],"attributes":{"department":null}}',
            ['items' => 'strings', 'attributes' => 'string_map']
        );
        $this->assertSame(['openid', ''], $collectionNulls['items']);
        $this->assertSame(['department' => ''], $collectionNulls['attributes']);
    }

    private function decode(string $raw, array $schema): array
    {
        /** @var ContractRequest $request */
        $request = Yii::$app->request;
        $request->raw = $raw;
        $request->setBodyParams(json_decode($raw, true));

        return (new ExposedApiController('contract', Yii::$app))->decodeBody(array_keys($schema), $schema);
    }
}

class ExposedApiController extends ApiController
{
    public function decodeBody(array $fields, array $schema): array
    {
        return $this->body($fields, $schema);
    }
}

class ContractRequest extends Request
{
    /** @var string */
    public $raw = '';

    public function getRawBody()
    {
        return $this->raw;
    }
}
