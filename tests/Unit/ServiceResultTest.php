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
        $response = new Response();

        $payload = (new HttpResultMapper())->map($result, $response);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(409, $response->statusCode);
        $this->assertSame('user_conflict', $result->error->code);
        $this->assertSame('user already exists', $payload['error']);
        $this->assertArrayNotHasKey('code', $payload);
    }
}
