<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Support\SourceScan;

test('every write route (POST/PUT/PATCH/DELETE) type-hints a FormRequest', function () {
    $violations = collect(RouteFacade::getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/') || str_starts_with($r->uri(), 'admin/'))
        ->filter(fn (Route $r) => array_intersect($r->methods, ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->filter(fn (Route $r) => is_string($r->getAction('uses')) && str_contains((string) $r->getAction('uses'), '@'))
        ->reject(fn (Route $r) => str_starts_with($r->getAction('controller') ?? '', 'Closure'))
        ->filter(function (Route $r) {
            $parts = explode('@', (string) $r->getAction('controller'), 2);
            $class = $parts[0] ?? '';
            $method = $parts[1] ?? '__invoke'; // single-action controllers
            if ($class === '' || ! class_exists($class) || ! method_exists($class, $method)) {
                return true; // unresolvable = violation
            }

            return ! SourceScan::methodUsesFormRequest(new ReflectionMethod($class, $method));
        })
        ->map(fn (Route $r) => implode('|', array_diff($r->methods, ['HEAD'])).' '.$r->uri().' → '.$r->getAction('controller'))
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

test('form-request checker flags a violation and accepts a compliant controller (fixtures)', function () {
    eval('
        namespace Tests\Support\Fixtures;
        use Illuminate\Foundation\Http\FormRequest;
        class FakeRequest extends FormRequest { public function rules(): array { return []; } }
        class CompliantController { public function store(FakeRequest $r): string { return "ok"; } }
        class RogueController { public function store(\Illuminate\Http\Request $r): string { return "bad"; } }
    ');

    $check = fn (string $class, string $method): bool => SourceScan::methodUsesFormRequest(new ReflectionMethod($class, $method));

    expect($check('Tests\Support\Fixtures\CompliantController', 'store'))->toBeTrue();
    expect($check('Tests\Support\Fixtures\RogueController', 'store'))->toBeFalse();
});

/* ────────────────────────────── 005 admin plane ──────────────────────────── */

test('every /admin/* route carries the role:super-admin gate', function () {
    $violations = collect(RouteFacade::getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'admin/'))
        ->reject(fn (Route $r) => in_array('role:super-admin', $r->gatherMiddleware(), true))
        ->map(fn (Route $r) => implode('|', array_diff($r->methods, ['HEAD'])).' '.$r->uri())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

test('there are /admin/* routes to guard (rule is not vacuous)', function () {
    $n = collect(RouteFacade::getRoutes())->filter(fn (Route $r) => str_starts_with($r->uri(), 'admin/'))->count();
    expect($n)->toBeGreaterThan(10);
});

test('only the audit allow-list may write activity_log rows', function () {
    // WRITE channels only (reads via DB::table('activity_log') in the query
    // actions and the prune janitor are fine).
    $hits = SourceScan::findInProject(
        ['ActivityLogger', 'activity\(\)->', 'Activity::(create|log)', '->recordActivity\('],
        ['app'],
        ['app/Audit/', 'app/Models/Concerns/'],
    );

    // LogsActivity models legitimately reference the package internals via the trait only
    $traitUsers = collect(glob(base_path('app/Models/*.php')))
        ->filter(fn (string $f) => str_contains((string) file_get_contents($f), 'use LogsActivity'))
        ->map(fn (string $f) => 'app/Models/'.basename($f))
        ->all();

    $violations = collect($hits)
        ->reject(fn (string $hit) => str_starts_with($hit, 'app/Audit/') || str_starts_with($hit, 'app/Models/Concerns/'))
        ->reject(function (string $hit) use ($traitUsers) {
            foreach ($traitUsers as $f) {
                if (str_starts_with($hit, $f)) {
                    return true;
                }
            }

            return false;
        })
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

test('LogsActivity whitelists never include credential-shaped attributes', function () {
    $forbidden = ['password', 'remember_token', 'secret', 'recovery', 'two_factor', 'otp', 'token', 'totp'];
    $files = glob(base_path('app/Models/*.php')) ?: [];

    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        if (! str_contains($src, 'use LogsActivity')) {
            continue;
        }

        if (preg_match('/->logOnly\(\[(.*?)\]\)/s', $src, $m) === 1) {
            foreach ($forbidden as $needle) {
                expect(strtolower($m[1]))->not->toContain($needle, basename($file));
            }
        } else {
            expect($src)->toMatch('/->log(All|Fillable|Except)\(/');
        }
    }

    expect($files)->not->toBeEmpty();
});
