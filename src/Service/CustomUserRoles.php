<?php

declare(strict_types=1);

namespace Dbp\Relay\LdapPersonProviderBundle\Service;

use Dbp\Relay\AuthBundle\API\UserRolesInterface;

class CustomUserRoles implements UserRolesInterface
{
    private $ldap;

    public function __construct(LDAPApi $ldap)
    {
        $this->ldap = $ldap;
    }

    public function getRoles(?string $userIdentifier, array $scopes): array
    {
        // Convert all scopes to roles, like the default
        $roles = [];
        foreach ($scopes as $scope) {
            $roles[] = 'ROLE_SCOPE_'.mb_strtoupper($scope);
        }

        // In case we have a real user also merge in roles from LDAP
        if ($userIdentifier !== null) {
            $personRoles = $this->ldap->getRolesForCurrentPerson();
            $roles = array_merge($roles, $personRoles);
            $roles = array_unique($roles);
            sort($roles, SORT_STRING);
        }

        return $roles;
    }
}
