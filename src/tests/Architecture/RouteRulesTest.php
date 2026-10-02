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
            [$class, $method] = [$parts[0] ?? '', $parts[1] ?? ''];
            if ($method === '' || ! class_exists($class) || ! method_exists($class, $method)) {
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
