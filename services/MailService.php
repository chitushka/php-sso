<?php

namespace app\services;

use Yii;

class MailService
{
    public function send(string $to, string $subject, string $body): bool
    {
        $host = (string)(Yii::$app->params['smtpHost'] ?? '');
        $from = (string)(Yii::$app->params['smtpFrom'] ?? '');

        if ($host === '' || $from === '') {
            // В dev-режиме повторяем Go-сервис: письмо с ссылкой пишется в лог.
            Yii::info([
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
            ], 'mail');
            return true;
        }

        return $this->sendSmtp(
            $host,
            (int)(Yii::$app->params['smtpPort'] ?? 25),
            (string)(Yii::$app->params['smtpUsername'] ?? ''),
            (string)(Yii::$app->params['smtpPassword'] ?? ''),
            $from,
            $to,
            $subject,
            $body,
            (bool)(Yii::$app->params['smtpStartTls'] ?? false)
        );
    }

    private function sendSmtp(
        string $host,
        int $port,
        string $username,
        string $password,
        string $from,
        string $to,
        string $subject,
        string $body,
        bool $startTls
    ): bool {
        $socket = @stream_socket_client($host . ':' . $port, $errno, $errstr, 10);
        if (!$socket) {
            Yii::warning('smtp connection failed: ' . $errstr, 'mail');
            return false;
        }

        try {
            if (!$this->expect($socket, 220)) {
                return false;
            }

            $this->command($socket, 'EHLO localhost', 250);
            if ($startTls) {
                if (!$this->command($socket, 'STARTTLS', 220)) {
                    return false;
                }
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return false;
                }
                $this->command($socket, 'EHLO localhost', 250);
            }

            if ($username !== '') {
                if (!$this->command($socket, 'AUTH LOGIN', 334)
                    || !$this->command($socket, base64_encode($username), 334)
                    || !$this->command($socket, base64_encode($password), 235)) {
                    return false;
                }
            }

            $message = implode("\r\n", [
                'From: ' . $from,
                'To: ' . $to,
                'Subject: ' . $subject,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=utf-8',
                '',
                $body,
            ]);

            return $this->command($socket, 'MAIL FROM:<' . $from . '>', 250)
                && $this->command($socket, 'RCPT TO:<' . $to . '>', 250)
                && $this->command($socket, 'DATA', 354)
                && $this->command($socket, $message . "\r\n.", 250)
                && $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $command, int $expectedCode): bool
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    private function expect($socket, int $expectedCode): bool
    {
        $line = '';
        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                return false;
            }
        } while (strlen($line) >= 4 && $line[3] === '-');

        $code = (int)substr($line, 0, 3);
        if ($code !== $expectedCode) {
            Yii::warning('smtp unexpected response: ' . trim($line), 'mail');
            return false;
        }

        return true;
    }
}
