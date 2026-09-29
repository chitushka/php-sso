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
}
