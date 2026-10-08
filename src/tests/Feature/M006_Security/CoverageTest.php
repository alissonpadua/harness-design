<?php

declare(strict_types=1);

namespace Tests\Feature\M006_Security;

use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Requests\Admin\UpdateRegistrationsRequest;
use App\Models\User;
use App\Support\ImageProcessor;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->seed(PlansSeeder::class);
});

test('image processor rejects undecodable bytes', function () {
    expect(fn () => (new ImageProcessor)->toNormalizedWebp('this is not an image'))
        ->toThrow(\RuntimeException::class, 'The uploaded image could not be decoded.');
});

test('settings controller requires an authenticated actor (defense-in-depth)', function () {
    $request = Request::create('/admin/v1/settings/registrations', 'PUT', ['open' => false]);
    $request->setUserResolver(fn () => null);
    $form = UpdateRegistrationsRequest::createFrom($request);

    $controller = app(SettingsController::class);
    expect(fn () => $controller->updateRegistrations($form))->toThrow(AuthenticationException::class);
});

test('public logo 404s when the path column is present but empty', function () {
    [$ada] = Tenancy::user();
    $org = Tenancy::org($ada, 'EmptyLogo');
    $org->forceFill(['logo_path' => ''])->save();

    $this->get('/api/v1/public/orgs/'.$org->slug.'/logo')->assertNotFound();
    $this->get('/api/v1/public/orgs/nope-missing/logo')->assertNotFound();
});

test('plan-api limiter falls back per-user for org-less users and per-ip for guests', function () {
    // guest branch: invoke the named limiter factory directly
    $factory = RateLimiter::limiter('plan-api');
    $limit = $factory(Request::create('/api/v1/orgs/1', 'GET'));
    expect($limit)->toBeInstanceOf(Limit::class);

    // org-less authenticated user branch
    $lone = User::factory()->create(['email' => 'lone@m006.test']);
    $token = $lone->createToken('web', ['*']);
    $this->withToken($token->plainTextToken)->getJson('/api/v1/orgs')->assertOk();
});

test('plan limiter degrades to 60/min when the current org no longer resolves', function () {
    [$u, $t] = Tenancy::user('stale@m006.test');
    $org = Tenancy::org($u, 'GoneCo');
    $u->forceFill(['current_organization_id' => $org->id])->save();
    $org->delete(); // soft-deleted: default scope can no longer find it

    $this->withToken($t)->getJson('/api/v1/orgs')->assertOk();
    expect(Cache::get('plan-rl:'.$org->id))->toBe(60);
});
