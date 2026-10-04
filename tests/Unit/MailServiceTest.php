<?php

namespace tests\Unit;

use app\services\MailService;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\console\Application;

class MailServiceTest extends TestCase
{
    public function testRejectsCrLfInjectionBeforeConnecting(): void
    {
        $previous = Yii::$app;
        try {
            Yii::$app = null;
            new Application([
                'id' => 'mail-unit',
                'basePath' => dirname(__DIR__, 2),
                'params' => [
                    'smtpHost' => 'smtp.example.test',
                    'smtpFrom' => 'noreply@example.test',
                ],
            ]);

            $mailer = new MailService();
            $this->assertFalse($mailer->send("victim@example.test\r\nBcc: attacker@example.test", 'subject', 'body'));
            $this->assertFalse($mailer->send('victim@example.test', "subject\r\nBcc: attacker@example.test", 'body'));
        } finally {
            Yii::$app = $previous;
        }
    }

    public function testUtf8SubjectUsesRfc2047Encoding(): void
    {
        $mailer = new MailService();
        $method = new \ReflectionMethod($mailer, 'subjectHeader');
        $header = $method->invoke($mailer, 'Подтверждение входа');

        $this->assertStringStartsWith('Subject: =?UTF-8?B?', (string)$header);
        $this->assertStringNotContainsString("\nBcc:", (string)$header);
    }

    public function testEhloParsesStartTlsAndAuthCapabilities(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        fwrite($pair[1], "250-mail.example.test\r\n250-STARTTLS\r\n250 AUTH LOGIN PLAIN\r\n");

        try {
            $method = new \ReflectionMethod(new MailService(), 'ehlo');
            $capabilities = $method->invoke(new MailService(), $pair[0]);

            $this->assertArrayHasKey('STARTTLS', $capabilities);
            $this->assertSame('LOGIN PLAIN', $capabilities['AUTH']);
            $this->assertSame("EHLO localhost\r\n", fgets($pair[1]));
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }
}
