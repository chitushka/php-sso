<?php

namespace app\components;

use app\services\contracts\ApplicationRuntimeInterface;
use Yii;
use yii\db\Connection;
use yii\web\Application;
use yii\web\Request;

final class YiiApplicationRuntime implements ApplicationRuntimeInterface
{
    private array $state = [];

    public function db(): Connection
    {
        return Yii::$app->db;
    }

    public function param(string $name, mixed $default = null): mixed
    {
        return Yii::$app->params[$name] ?? $default;
    }

    public function setState(string $name, mixed $value): void
    {
        $this->state[$name] = $value;
    }

    public function state(string $name, mixed $default = null): mixed
    {
        return $this->state[$name] ?? $default;
    }

    public function request(): Request
    {
        if (!(Yii::$app instanceof Application)) {
            throw new \LogicException('HTTP request is unavailable outside a web application');
        }

        return Yii::$app->request;
    }

    public function webRequest(): ?Request
    {
        return Yii::$app instanceof Application ? Yii::$app->request : null;
    }

    public function requestedRoute(): string
    {
        return (string)Yii::$app->requestedRoute;
    }
}
