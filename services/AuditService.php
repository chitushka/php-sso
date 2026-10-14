<?php

namespace app\services;

use app\models\AuditLog;

class AuditService
{
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_USER = 'user';
    public const ACTOR_CLIENT = 'client';

    /** @var string|null */
    private static $currentActorUserId;

    /** @var string */
    private static $currentActorType = self::ACTOR_SYSTEM;

    /** @var string|null */
    private static $currentActorId;

    public static function setCurrentActor(?string $actorUserId): void
    {
        self::$currentActorUserId = $actorUserId;
        self::$currentActorType = $actorUserId === null ? self::ACTOR_SYSTEM : self::ACTOR_USER;
        self::$currentActorId = null;
    }

    public static function setCurrentPrincipal(string $type, ?string $id): void
    {
        if (!in_array($type, [self::ACTOR_SYSTEM, self::ACTOR_USER, self::ACTOR_CLIENT], true)) {
            throw new \InvalidArgumentException('unsupported audit principal type');
        }
        if ($type !== self::ACTOR_SYSTEM && ($id === null || $id === '')) {
            throw new \InvalidArgumentException('audit principal id is required');
        }

        self::$currentActorType = $type;
        self::$currentActorUserId = $type === self::ACTOR_USER ? $id : null;
        self::$currentActorId = $type === self::ACTOR_CLIENT ? $id : null;
    }

    public static function record(
        ?string $actorUserId,
        string $action,
        string $targetType,
        string $targetId,
        bool $includeRequestContext = false
    ): void {
        $log = new AuditLog();
        if ($actorUserId !== null) {
            $log->actor_user_id = $actorUserId;
            $log->setAttribute('actor_type', self::ACTOR_USER);
            $log->setAttribute('actor_id', null);
        } else {
            $log->setAttribute('actor_user_id', self::$currentActorUserId);
            $log->setAttribute('actor_type', self::$currentActorType);
            $log->setAttribute('actor_id', self::$currentActorId);
        }
        $log->action = $action;
        $log->target_type = $targetType;
        $log->target_id = $targetId;
        $request = $includeRequestContext ? ApplicationRuntime::webRequest() : null;
        $log->ip = $request?->userIP;
        $log->user_agent = $request?->userAgent;
        if (!$log->save(false)) {
            throw new \RuntimeException('failed to persist audit event');
        }
    }
}
