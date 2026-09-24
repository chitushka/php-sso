<?php

namespace app\components;

final class Rfc3339
{
    public static function format($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s.uP');
        }

        $date = new \DateTimeImmutable((string)$value);
        $date = $date->setTimezone(new \DateTimeZone('UTC'));
        $fraction = rtrim($date->format('u'), '0');

        return $date->format('Y-m-d\\TH:i:s')
            . ($fraction === '' ? '' : '.' . $fraction)
            . 'Z';
    }
}
