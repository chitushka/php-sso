<?php

namespace tests\Unit;

use app\services\results\HttpResultMapper;
use app\services\results\ServiceError;
use app\services\results\ServiceResult;
use PHPUnit\Framework\TestCase;
use yii\web\Response;

final class ServiceResultTest extends TestCase
{
    public function testSuccessCarriesTypedStatusAndPayload(): void
    {
        $result = ServiceResult::success(
            ['id' => 'user-id'],
            201,
            ['redirect' => 'https://client.example.test/callback']
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame(201, $result->status);
        $this->assertSame(['id' => 'user-id'], $result->data);
        $this->assertSame('https://client.example.test/callback', $result->metadata['redirect']);
        $this->assertSame(201, $result['status']);
    }

    public function testErrorMapperPreservesPublicEnvelopeAndStatus(): void
    {
        $result = ServiceResult::failure(new ServiceError(
            'user_conflict',
            'user already exists',
            409,
            ['details' => ['email' => ['duplicate']]]
        ));
        $response = new Response(['charset' => 'UTF-8']);

        $payload = (new HttpResultMapper())->map($result, $response);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(409, $response->statusCode);
        $this->assertSame('user_conflict', $result->error->code);
        $this->assertSame('user already exists', $payload['error']);
        $this->assertSame('user_conflict', $payload['code']);
        $this->assertSame(['email' => ['duplicate']], $payload['details']);
    }

    public function testReadOnlyPropertiesKeepTheirSerializationAndIssetBehavior(): void
    {
        $error = new ServiceError('forbidden', 'access denied', 403, ['reason' => 'permission']);
        $result = ServiceResult::failure($error);

        $this->assertTrue(isset($result->status));
        $this->assertTrue(isset($result->error));
        $this->assertFalse(isset(ServiceResult::success([])->error));
        $this->assertFalse(isset($result->unknown));
        $this->assertFalse(isset($error->unknown));
        $this->assertTrue(isset($error->details));
        $this->assertNull($result['unknown']);
        $this->assertNull($result[0]);
        $this->assertSame([
            'status' => 403,
            'data' => ['error' => 'access denied', 'reason' => 'permission'],
            'error' => ['code' => 'forbidden', 'message' => 'access denied', 'status' => 403, 'details' => ['reason' => 'permission']],
            'metadata' => [],
        ], json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @dataProvider immutableOperations */
    public function testResultsAndErrorsRejectMutation(string $operation): void
    {
        $result = ServiceResult::failure(new ServiceError('forbidden', 'access denied', 403));

        $this->expectException(\LogicException::class);
        switch ($operation) {
            case 'result write':
                $result->status = 200;
                break;
            case 'result unset':
                unset($result->status);
                break;
            case 'result offset write':
                $result['status'] = 200;
                break;
            case 'result offset unset':
                unset($result['status']);
                break;
            case 'error write':
                $result->error->code = 'success';
                break;
            case 'error unset':
                unset($result->error->code);
                break;
            case 'result unknown read':
                $result->unknown;
                break;
            case 'error unknown read':
                $result->error->unknown;
                break;
        }
    }

    public static function immutableOperations(): array
    {
        return array_map(static function (string $operation): array {
            return [$operation];
        }, [
            'result write', 'result unset', 'result offset write', 'result offset unset',
            'error write', 'error unset', 'result unknown read', 'error unknown read',
        ]);
    }
}
