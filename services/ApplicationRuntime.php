<?php

namespace app\services;

use app\components\YiiApplicationRuntime;
use app\services\contracts\ApplicationRuntimeInterface;
use yii\db\Connection;
use yii\web\Request;

final class ApplicationRuntime
{
    private static ?ApplicationRuntimeInterface $instance = null;

    public static function replace(?ApplicationRuntimeInterface $runtime): void
    {
        self::$instance = $runtime;
    }

    public static function db(): Connection
    {
        return self::instance()->db();
    }

    public static function param(string $name, mixed $default = null): mixed
    {
        return self::instance()->param($name, $default);
    }

    public static function setState(string $name, mixed $value): void
    {
        self::instance()->setState($name, $value);
    }

    public static function state(string $name, mixed $default = null): mixed
    {
        return self::instance()->state($name, $default);
    }

    public static function request(): Request
    {
        return self::instance()->request();
    }

    public static function webRequest(): ?Request
    {
        return self::instance()->webRequest();
    }

    public static function requestedRoute(): string
    {
        return self::instance()->requestedRoute();
    }

    private static function instance(): ApplicationRuntimeInterface
    {
        return self::$instance ??= new YiiApplicationRuntime();
    }
}
