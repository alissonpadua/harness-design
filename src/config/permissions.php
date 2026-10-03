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
    ],

];
