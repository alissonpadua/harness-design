<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Permission Catalog (spec 001 AC-001.23)
|--------------------------------------------------------------------------
|
| Single source of truth for the global permission plane: every capability
| is a `resource.action` name, seeded by RolesSeeder, checked via spatie
| middleware/policies. Team-scoped roles (002) reuse these names on pivots.
| Modules append their entries via spec tasks — never string literals
| scattered in code.
|
*/

return [

    'catalog' => [
        'admin.access' => 'Enter the admin plane (/admin/v1/*)',

        // spec 002 — organization plane
        'org.view' => 'Read organization profile and settings',
        'org.update' => 'Change organization settings',
        'org.delete' => 'Delete the organization (owner only)',
        'org.transfer' => 'Transfer ownership (owner only)',
        'members.view' => 'List members',
        'members.invite' => 'Invite members',
        'tokens.manage' => 'Create and revoke integration tokens (spec 006)',
        'members.update_role' => 'Change member roles',
        'members.suspend' => 'Suspend / reactivate members',
        'members.remove' => 'Remove members',
        'invites.view' => 'List pending invites',
        'invites.revoke' => 'Revoke pending invites',
        'invite_links.manage' => 'Create / revoke invite links',
        'audit.view' => 'View the organization activity feed (spec 005)',
        'billing.view' => 'View billing/portal (spec 003)',
        'billing.manage' => 'Run checkout, change plans, manage payment methods (spec 003)',
    ],

];
