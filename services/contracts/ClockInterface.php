<?php

namespace app\services\contracts;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
