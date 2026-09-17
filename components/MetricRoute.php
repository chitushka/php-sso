<?php

namespace app\components;

class MetricRoute
{
    private const DYNAMIC_ROUTES = [
        'auth/revoke-session' => '/api/v1/auth/sessions/{id}',
        'users/view' => '/api/v1/users/{id}',
        'users/update' => '/api/v1/users/{id}',
        'users/delete' => '/api/v1/users/{id}',
        'rbac/list-user-roles' => '/api/v1/users/{userID}/roles',
        'rbac/assign-user-role' => '/api/v1/users/{userID}/roles',
        'rbac/remove-user-role' => '/api/v1/users/{userID}/roles/{roleID}',
        'groups/list-user-groups' => '/api/v1/users/{userID}/groups',
        'groups/assign-user-group' => '/api/v1/users/{userID}/groups',
        'groups/remove-user-group' => '/api/v1/users/{userID}/groups/{groupID}',
        'rbac/update-role' => '/api/v1/roles/{roleID}',
        'rbac/delete-role' => '/api/v1/roles/{roleID}',
        'rbac/list-role-permissions' => '/api/v1/roles/{roleID}/permissions',
        'rbac/assign-permission' => '/api/v1/roles/{roleID}/permissions',
        'rbac/remove-permission' => '/api/v1/roles/{roleID}/permissions/{permissionID}',
        'groups/update' => '/api/v1/groups/{groupID}',
        'groups/delete' => '/api/v1/groups/{groupID}',
        'groups/list-roles' => '/api/v1/groups/{groupID}/roles',
        'groups/assign-role' => '/api/v1/groups/{groupID}/roles',
        'groups/remove-role' => '/api/v1/groups/{groupID}/roles/{roleID}',
        'ldap/update-provider' => '/api/v1/ldap/providers/{id}',
        'ldap/delete-provider' => '/api/v1/ldap/providers/{id}',
        'ldap/list-group-mappings' => '/api/v1/ldap/providers/{id}/group-mappings',
        'ldap/create-group-mapping' => '/api/v1/ldap/providers/{id}/group-mappings',
        'ldap/delete-group-mapping' => '/api/v1/ldap/providers/{id}/group-mappings/{mappingID}',
        'broker/update-provider' => '/api/v1/identity-providers/{id}',
        'broker/delete-provider' => '/api/v1/identity-providers/{id}',
        'broker/login' => '/oauth2/broker/{code}/login',
        'broker/callback' => '/oauth2/broker/{code}/callback',
        'oauth/get-client' => '/api/v1/oauth/clients/{id}',
        'oauth/update-client' => '/api/v1/oauth/clients/{id}',
        'oauth/delete-client' => '/api/v1/oauth/clients/{id}',
        'oauth/rotate-secret' => '/api/v1/oauth/clients/{id}/rotate-secret',
    ];

    public static function label(string $requestedRoute, string $pathInfo): string
    {
        if (isset(self::DYNAMIC_ROUTES[$requestedRoute])) {
            return self::DYNAMIC_ROUTES[$requestedRoute];
        }

        if ($requestedRoute === 'admin/index') {
            return 'other';
        }

        $path = trim($pathInfo, '/');
        return $path === '' ? 'other' : '/' . $path;
    }
}
