<?php

namespace app\services\contracts;

use yii\db\Connection;
use yii\web\Request;

interface ApplicationRuntimeInterface
{
    public function db(): Connection;

    public function param(string $name, mixed $default = null): mixed;

    public function setState(string $name, mixed $value): void;

    public function state(string $name, mixed $default = null): mixed;

    public function request(): Request;

    public function webRequest(): ?Request;

    public function requestedRoute(): string;
}
