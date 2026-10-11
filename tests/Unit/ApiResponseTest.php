<?php

namespace tests\Unit;

use app\components\ApiResponse;
use PHPUnit\Framework\TestCase;

final class ApiResponseTest extends TestCase
{
    public function testErrorHasStableMachineReadableCode(): void
    {
        $this->assertSame(
            ['error' => 'resource not found', 'code' => 'resource_not_found'],
            ApiResponse::error('resource not found')
        );
    }

    public function testExistingErrorCodeIsNotOverwritten(): void
    {
        $this->assertSame(
            ['error' => 'duplicate', 'code' => 'user_conflict'],
            ApiResponse::normalizeError(['error' => 'duplicate', 'code' => 'user_conflict'])
        );
    }

    public function testFrameworkErrorIsConvertedToPublicEnvelope(): void
    {
        $this->assertSame(
            ['error' => 'Page not found.', 'code' => 'page_not_found'],
            ApiResponse::normalizeError([
                'name' => 'Not Found',
                'message' => 'Page not found.',
                'status' => 404,
            ])
        );
    }

    public function testPageContainsItemsAndRequestMetadata(): void
    {
        $this->assertSame([
            'items' => [['id' => 'first'], ['id' => 'second']],
            'pagination' => ['limit' => 50, 'offset' => 100, 'count' => 2],
        ], ApiResponse::page([['id' => 'first'], ['id' => 'second']], 50, 100));
    }
}
