<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\SourceScan;

arch('controllers never reach for the DB facade')
    ->expect('App\Http\Controllers')
    ->not->toUse([DB::class]);

test('mass assignment ban: $request->all() and ->fill($request->all()) never in app/ or routes/', function () {
    expect(SourceScan::findInProject(['\$request->all\(\)', 'request\(\)->all\(\)']))->toBe([]);
});

test('mass assignment scanner detects a violating snippet (negative fixture)', function () {
    $hits = SourceScan::scanString('<?php $u = User::create($request->all());', '\$request->all\(\)');
    expect($hits)->not->toBeEmpty();
});

test('stripe and cashier imports are confined to app/Billing', function () {
    $hits = SourceScan::findInProject(['^use Stripe\\\\', '^use Laravel\\\\Cashier\\\\'], ['app', 'routes'], ['app/Billing']);
    expect($hits)->toBe([]);
});

test('billing isolation scanner works (positive + negative fixtures)', function () {
    expect(SourceScan::scanString("<?php\nuse Stripe\\StripeClient;", '^use Stripe\\\\'))->not->toBeEmpty();
    expect(SourceScan::scanString("<?php\nuse App\\Billing\\PaymentGateway;", '^use Stripe\\\\'))->toBeEmpty();
});

test('controllers contain no writes: ->save(, ->create(, DB:: outside whitelisted constructs', function () {
    $hits = SourceScan::findInDirectory(
        'app/Http/Controllers',
        ['->save\(', '::create\(', '\bdatabase\(\)->', 'new\s+App\\\\Models\\\\']
    );
    expect($hits)->toBe([]);
});

test('actions never call mailers or http clients directly — dispatch events instead', function () {
    $hits = SourceScan::findInDirectory('app/Actions', [
        'Facades\\\\Mail::', '->notify\(', 'Facades\\\\Http::', 'Http::(get|post|put|delete)\(', '->send\(',
    ]);
    expect($hits)->toBe([]);
});

test('every *Action class lives in the App\Actions namespace', function () {
    $misplaced = [];
    foreach (SourceScan::projectPhpFiles(['app']) as $file) {
        if (preg_match('/class\s+(\w*Action)\s/', (string) file_get_contents($file)) && ! str_contains((string) file_get_contents($file), 'namespace App\Actions')) {
            $misplaced[] = $file;
        }
    }
    expect($misplaced)->toBe([]);
});

test('action placement scanner works (negative fixture)', function () {
    $content = "<?php namespace App\Http; class MakeCoffeeAction {}";
    $isMisplaced = preg_match('/class\s+\w*Action\s/', $content) === 1 && ! str_contains($content, 'namespace App\Actions');
    expect($isMisplaced)->toBeTrue();
});

test('readonly-by-default: every Action and Middleware class is declared final readonly', function () {
    $offenders = [];

    foreach (['app/Actions', 'app/Http/Middleware'] as $dir) {
        foreach (SourceScan::projectPhpFiles([$dir]) as $file) {
            $content = (string) file_get_contents($file);

            if (preg_match('/^(final\s+)?(readonly\s+)?class/m', $content) === 1
                && preg_match('/^final readonly class/m', $content) !== 1) {
                $offenders[] = ltrim(str_replace(dirname(__DIR__, 2), '', $file), '/');
            }
        }
    }

    expect($offenders)->toBe([], 'docs/conventions.md: stateless classes must be `final readonly`; justify mutability via ADR + human approval');
});

test('readonly rule scanner works (positive + negative fixtures)', function () {
    $bad = "<?php\nnamespace App\\Actions;\nfinal class LooseAction {}";
    $good = "<?php\nnamespace App\\Actions;\nfinal readonly class TightAction {}";
    $hasClass = fn (string $c): bool => preg_match('/^(final\s+)?(readonly\s+)?class/m', $c) === 1;
    $isTight = fn (string $c): bool => preg_match('/^final readonly class/m', $c) === 1;

    expect($hasClass($bad))->toBeTrue();
    expect($isTight($bad))->toBeFalse();
    expect($isTight($good))->toBeTrue();
});
