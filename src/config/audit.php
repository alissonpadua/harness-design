<?php

declare(strict_types=1);

return [

    /*
    | Platform audit retention days (Q2); the prune command is the only deleter.
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    /*
    | Security event names (explicit log via AuditSecurityEvent).
    */
    'events' => [
        'settings_change',
        'integration_token_created',
        'integration_token_revoked',
        'admin_login', 'user_suspend', 'user_unsuspend', 'user_restore', 'user_force_delete',
        'org_restore', 'org_plan_change', 'org_entitlement_override',
        'impersonation_start', 'impersonation_stop', 'impersonated_request',
        'webhook_replay', 'horizon_link',
    ],
];
