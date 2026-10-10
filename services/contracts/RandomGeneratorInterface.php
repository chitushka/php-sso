<?php

namespace app\services\contracts;

interface RandomGeneratorInterface
{
    public function token(int $bytes = 32): string;
}
