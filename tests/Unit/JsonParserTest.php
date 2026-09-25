<?php

namespace tests\Unit;

use app\components\JsonParser;
use PHPUnit\Framework\TestCase;
use yii\web\BadRequestHttpException;

class JsonParserTest extends TestCase
{
    public function testParsesJsonObjectAndNullLikeGoStructDecoder(): void
    {
        $parser = new JsonParser();

        $this->assertSame(['login' => 'admin'], $parser->parse('{"login":"admin"}', 'application/json'));
        $this->assertSame([], $parser->parse('null', 'application/json'));
        $this->assertSame(['login' => 'admin'], $parser->parse('{"login":"admin"}{"ignored":true}', 'application/json'));
    }

    /**
     * @dataProvider invalidBodyProvider
     */
    public function testRejectsMalformedAndNonObjectJson(string $body): void
    {
        $parser = new JsonParser();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('invalid json body');
        $parser->parse($body, 'application/json');
    }

    public function invalidBodyProvider(): array
    {
        return [
            'malformed object' => ['{"login":'],
            'array' => ['[]'],
            'string' => ['"admin"'],
        ];
    }
}
