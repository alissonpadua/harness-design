<?php

declare(strict_types=1);

namespace Tests\Feature\M004_Notifications;

use App\Notifications\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('T0-5 markdown link syntax in user-controlled names is defanged in mail', function () {
    Mail::fake();
    $catalog = app(NotificationCatalog::class);

    $evil = '[Payroll invoice](https://evil.example) <script>alert(1)</script> `code` (paren)';

    $html = $catalog->get('org.invite_received')->mailable([
        'org_name' => $evil, 'role' => 'member', 'url' => 'http://api.test/accept-invite/tok',
    ])->render();

    expect($html)->not->toContain('<a href="https://evil.example">')
        ->and($html)->not->toContain('<script>')
        ->and(strip_tags($html))->toContain('Payroll invoice')   // text still visible (escaped, inert)
        ->and($html)->toContain('http://api.test/accept-invite/tok'); // real action button intact

    $joined = $catalog->get('org.member_joined')->mailable([
        'org_name' => 'Clean Org', 'member_name' => '[x](http://evil)', 'role' => 'member',
    ])->render();
    expect($joined)->not->toContain('<a href="http://evil"');
});
