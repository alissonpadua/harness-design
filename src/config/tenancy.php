<?php

declare(strict_types=1);

return [

    /*
    | Limits are the pre-003 stand-in for plan entitlements: bound via
    | Contracts\Org\OrgEntitlements, swapped to DB plans in spec 003.
    */
    'limits' => [
        'max_teams' => (int) env('TENANCY_MAX_TEAMS', 3),
        'max_members' => (int) env('TENANCY_MAX_MEMBERS', 10),
    ],

    'invites' => [
        'ttl_days' => 7,
        'link_max_ttl_days' => 30,
    ],

    'personal_workspace' => [
        'name_pattern' => "%s's workspace",
    ],

];
