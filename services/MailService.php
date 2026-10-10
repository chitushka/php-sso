<?php

namespace app\services;

use Yii;

class MailService
{
    public function send(string $to, string $subject, string $body): bool
    {
        $host = (string)ApplicationRuntime::param('smtpHost', '');
        $from = (string)ApplicationRuntime::param('smtpFrom', '');

        if ($host === '' || $from === '') {
            // Секретная ссылка никогда не должна попадать в журнал.
            Yii::warning('mail delivery is not configured', 'mail');
            (new MetricsService())->event('mail', 'failure');
            return false;
        }

        if (!$this->validHost($host) || !$this->validAddress($to) || !$this->validAddress($from) || $this->hasHeaderBreak($subject)) {
            Yii::warning('mail address or subject is invalid', 'mail');
            (new MetricsService())->event('mail', 'failure');
            return false;
        }

        $username = (string)ApplicationRuntime::param('smtpUsername', '');
        $startTls = (bool)ApplicationRuntime::param('smtpStartTls', false);
        if ($username !== '' && !$startTls) {
            Yii::warning('smtp credentials require STARTTLS', 'mail');
            (new MetricsService())->event('mail', 'failure');
            return false;
        }

        $sent = $this->sendSmtp(
            $host,
            (int)ApplicationRuntime::param('smtpPort', 25),
            $username,
            (string)ApplicationRuntime::param('smtpPassword', ''),
            $from,
            $to,
            $subject,
            $body,
            $startTls
        );
        (new MetricsService())->event('mail', $sent ? 'success' : 'failure');

        return $sent;
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
        $ca = (string)ApplicationRuntime::param('smtpCaCertificate', '');
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
            Yii::warning('smtp connection failed', 'mail');
            return false;
        }

        try {
            stream_set_timeout($socket, 10);
            if (!$this->expect($socket, 220)) {
                return false;
            }

            $capabilities = $this->ehlo($socket);
            if ($capabilities === null) {
                return false;
            }
            if ($startTls) {
                if (!isset($capabilities['STARTTLS'])) {
                    Yii::warning('smtp server does not advertise STARTTLS', 'mail');
                    return false;
                }
                if (!$this->command($socket, 'STARTTLS', 220)) {
                    return false;
                }
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return false;
                }
                $capabilities = $this->ehlo($socket);
                if ($capabilities === null) {
                    return false;
                }
            }

            if ($username !== '') {
                if (
                    !isset($capabilities['AUTH'])
                    || stripos(' ' . $capabilities['AUTH'] . ' ', ' LOGIN ') === false
                ) {
                    Yii::warning('smtp server does not advertise AUTH LOGIN', 'mail');
                    return false;
                }
                if (
                    !$this->command($socket, 'AUTH LOGIN', 334)
                    || !$this->command($socket, base64_encode($username), 334)
                    || !$this->command($socket, base64_encode($password), 235)
                ) {
                    return false;
                }
            }

            $safeBody = quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $body));
            $safeBody = preg_replace('/(?m)^\./', '..', $safeBody);
            $safeBody = str_replace("\n", "\r\n", (string)$safeBody);
            $subjectHeader = $this->subjectHeader($subject);
            if ($subjectHeader === null) {
                return false;
            }
            $message = implode("\r\n", [
                'From: ' . $from,
                'To: ' . $to,
                $subjectHeader,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=utf-8',
                'Content-Transfer-Encoding: quoted-printable',
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
        $response = $this->readResponse($socket);
        return $response !== null && $response['code'] === $expectedCode;
    }

    private function ehlo($socket): ?array
    {
        fwrite($socket, "EHLO localhost\r\n");
        $response = $this->readResponse($socket);
        if ($response === null || $response['code'] !== 250) {
            return null;
        }

        $capabilities = [];
        foreach ($response['lines'] as $line) {
            $value = trim(substr($line, 4));
            if ($value === '') {
                continue;
            }
            [$name, $arguments] = array_pad(preg_split('/\s+/', $value, 2), 2, '');
            $capabilities[strtoupper($name)] = $arguments;
        }

        return $capabilities;
    }

    private function readResponse($socket): ?array
    {
        $lines = [];
        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                return null;
            }
            $lines[] = $line;
        } while (strlen($line) >= 4 && $line[3] === '-');

        if (preg_match('/^\d{3}[ -]/', $line) !== 1) {
            return null;
        }

        return ['code' => (int)substr($line, 0, 3), 'lines' => $lines];
    }

    private function subjectHeader(string $subject): ?string
    {
        if (strlen($subject) > 998 || !preg_match('//u', $subject)) {
            return null;
        }
        if (preg_match('/^[\x20-\x7E]*$/D', $subject) === 1) {
            return 'Subject: ' . $subject;
        }
        if (!function_exists('iconv_mime_encode')) {
            return null;
        }

        $encoded = iconv_mime_encode('Subject', $subject, [
            'scheme' => 'B',
            'input-charset' => 'UTF-8',
            'output-charset' => 'UTF-8',
            'line-length' => 76,
            'line-break-chars' => "\r\n",
        ]);

        return $encoded === false ? null : $encoded;
    }
}
