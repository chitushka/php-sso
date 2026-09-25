<?php

namespace app\components;

final class GoJson
{
    public static function firstValue(string $rawBody): string
    {
        $value = ltrim($rawBody);
        if (strncmp($value, 'null', 4) === 0) {
            return 'null';
        }
        if ($value === '' || ($value[0] !== '{' && $value[0] !== '[')) {
            return $value;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{' || $character === '[') {
                $depth++;
            } elseif ($character === '}' || $character === ']') {
                $depth--;
                if ($depth === 0) {
                    // json.Decoder.Decode читает только первое значение JSON-потока.
                    return substr($value, 0, $index + 1);
                }
            }
        }

        return $value;
    }
}
