<?php

namespace app\services\contracts;

use yii\db\Connection;
use yii\web\Request;

interface ApplicationRuntimeInterface
{
    public function db(): Connection;

    public function param(string $name, $default = null);

    public function setState(string $name, $value): void;

    public function state(string $name, $default = null);

    public function request(): Request;

    public function webRequest(): ?Request;

    public function requestedRoute(): string;
}
