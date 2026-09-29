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
            // Секретная ссылка никогда не должна попадать в журнал.
            Yii::warning('mail delivery is not configured', 'mail');
            return false;
        }

        if (!$this->validHost($host) || !$this->validAddress($to) || !$this->validAddress($from) || $this->hasHeaderBreak($subject)) {
            Yii::warning('mail address or subject is invalid', 'mail');
            return false;
        }

        $username = (string)(Yii::$app->params['smtpUsername'] ?? '');
        $startTls = (bool)(Yii::$app->params['smtpStartTls'] ?? false);
        if ($username !== '' && !$startTls) {
            Yii::warning('smtp credentials require STARTTLS', 'mail');
            return false;
        }

        return $this->sendSmtp(
            $host,
            (int)(Yii::$app->params['smtpPort'] ?? 25),
            $username,
            (string)(Yii::$app->params['smtpPassword'] ?? ''),
            $from,
            $to,
            $subject,
            $body,
            $startTls
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
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ];
        $ca = (string)(Yii::$app->params['smtpCaCertificate'] ?? '');
        if ($ca !== '') {
            $ssl['cafile'] = $ca;
        }
        $context = stream_context_create(['ssl' => $ssl]);
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
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

            $safeBody = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r"], "\n", $body));
            $safeBody = str_replace("\n", "\r\n", (string)$safeBody);
            $message = implode("\r\n", [
                'From: ' . $from,
                'To: ' . $to,
                'Subject: ' . $subject,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=utf-8',
                '',
                $safeBody,
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

    private function validAddress(string $address): bool
    {
        return !$this->hasHeaderBreak($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validHost(string $host): bool
    {
        return preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])$/D', $host) === 1;
    }

    private function hasHeaderBreak(string $value): bool
    {
        return strpos($value, "\r") !== false || strpos($value, "\n") !== false;
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
