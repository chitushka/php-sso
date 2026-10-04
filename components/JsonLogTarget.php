<?php

namespace app\components;

use Yii;
use yii\log\Logger;
use yii\log\Target;

class JsonLogTarget extends Target
{
    public $logVars = [];

    public function export(): void
    {
        $stream = fopen('php://stderr', 'ab');
        if ($stream === false) {
            return;
        }
        try {
            foreach ($this->messages as $message) {
                fwrite($stream, json_encode($this->record($message), JSON_UNESCAPED_SLASHES) . "\n");
            }
        } finally {
            fclose($stream);
        }
    }

    private function record(array $message): array
    {
        [$text, $level, $category, $timestamp] = $message;
        if ($text instanceof \Throwable) {
            $text = get_class($text) . ': ' . $text->getMessage();
        } elseif (!is_string($text)) {
            $text = json_encode($this->redact($text), JSON_UNESCAPED_SLASHES);
        }

        return [
            'timestamp' => gmdate('Y-m-d\TH:i:s.u\Z', (int)$timestamp),
            'level' => Logger::getLevelName($level),
            'category' => (string)$category,
            'message' => $this->redactText((string)$text),
            'request_id' => (string)(Yii::$app->params['_requestId'] ?? ''),
        ];
    }

    private function redact($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (preg_match('/token|secret|password|authorization|cookie/i', (string)$key)) {
                $value[$key] = '[REDACTED]';
            } else {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }

    private function redactText(string $message): string
    {
        return preg_replace(
            '/((?:token|secret|password|authorization|cookie)["\s:=]+)[^\s,;}]+/i',
            '$1[REDACTED]',
            $message
        ) ?? '[log message redacted]';
    }
}
