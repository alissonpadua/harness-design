<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Organization Role → Permission Map (spec 002)
|--------------------------------------------------------------------------
|
| Built-in roles ONLY (custom roles cut from scope). No implicit inheritance:
| every grant is listed. `owner` carries the '*' wildcard short-circuit in
| App\Auth\OrgAuthorizer.
|
*/

return [

    'viewer' => [
        'org.view',
        'members.view',
    ],

    'member' => [
        'org.view',
        'members.view',
    ],

    'admin' => [
        'org.view',
        'org.update',
        'members.view',
        'members.invite',
        'members.update_role',
        'members.suspend',
        'members.remove',
        'invites.view',
        'invites.revoke',
        'invite_links.manage',
        'billing.view',
        'billing.manage',
        'audit.view',
    ],

    'owner' => [
        '*',
    ],

];
