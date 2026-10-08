<?php

declare(strict_types=1);

namespace Tests\Feature\M006_Security;

use App\Models\Organization;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tenancy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->seed(PlansSeeder::class);
});

function logoPng(int $w = 1200, int $h = 900, ?string $path = null): string
{
    $path ??= tempnam(sys_get_temp_dir(), 'lg').'.png';
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
    imagepng($im, $path);
    imagedestroy($im);

    return $path;
}

/* ───────────────────────── upload + re-encode ─────────────────────────────── */

test('AC-006.8 owner uploads logo -> re-encoded webp on private disk, capped dimensions, audited url', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LogoCo');
    $src = logoPng();

    $res = $this->withToken($adaToken)->putJson('/api/v1/orgs/'.$org->id.'/logo', [
        'logo' => UploadedFile::fake()->createWithContent('team.png', (string) file_get_contents($src)),
    ])->assertOk();

    $org->refresh();
    expect($org->logo_path)->toBe('orgs/'.$org->id.'/logo.webp')
        ->and($res->json('data.logo_url'))->toBe(route('api.v1.public.logo', $org->slug));

    $stored = (string) Storage::get($org->logo_path);
    expect(substr($stored, 0, 4))->toBe('RIFF')
        ->and(substr($stored, 8, 4))->toBe('WEBP')
        ->and($stored)->not->toBe(file_get_contents($src)); // re-encoded, not copied

    [$w, $h] = getimagesizefromstring($stored);
    expect($w)->toBe(1024)->and($h)->toBe(768);
});

test('AC-006.8 content is sniffed with finfo, filename lies rejected', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LogoEvil');

    $payload = "<?php system(\$_GET['c']); ?> never an image";
    $this->withToken($adaToken)->putJson('/api/v1/orgs/'.$org->id.'/logo', [
        'logo' => UploadedFile::fake()->createWithContent('evil.png', $payload),
    ])->assertStatus(422);

    expect($org->refresh()->logo_path)->toBeNull();
});

test('AC-006.8 oversize upload rejected before any write', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LogoBig');
    $src = logoPng();
    $big = tempnam(sys_get_temp_dir(), 'bg').'.png';
    file_put_contents($big, (string) file_get_contents($src).str_repeat('0', 3 * 1024 * 1024));

    $this->withToken($adaToken)->putJson('/api/v1/orgs/'.$org->id.'/logo', [
        'logo' => UploadedFile::fake()->createWithContent('big.png', (string) file_get_contents($big)),
    ])->assertStatus(422);

    expect($org->refresh()->logo_path)->toBeNull();
});

test('AC-006.8 member role cannot set the org logo', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LogoRole');
    [$bob, $bobToken] = Tenancy::user('bob@logo.test');
    $org->memberships()->create(['user_id' => $bob->id, 'role' => 'member']);

    $this->withToken($bobToken)->putJson('/api/v1/orgs/'.$org->id.'/logo', [
        'logo' => UploadedFile::fake()->createWithContent('s.png', (string) file_get_contents(logoPng(64, 64))),
    ])->assertForbidden();
});

/* ───────────────────────── public serving ─────────────────────────────────── */

test('AC-006.8 public route streams webp bytes unauthenticated (local driver)', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LogoServe');

    $this->getJson('/api/v1/public/orgs/'.$org->slug.'/logo')->assertNotFound();

    $this->withToken($adaToken)->putJson('/api/v1/orgs/'.$org->id.'/logo', [
        'logo' => UploadedFile::fake()->createWithContent('s.png', (string) file_get_contents(logoPng(200, 100))),
    ])->assertOk();

    $res = $this->get('/api/v1/public/orgs/'.$org->slug.'/logo')->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('image/webp')
        ->and($res->headers->get('Cache-Control'))->toContain('max-age=300')
        ->and(substr($res->getContent(), 8, 4))->toBe('WEBP');
});

test('AC-006.8 s3-backed disk answers with a short signed redirect', function () {
    Storage::fake('s3');
    config(['filesystems.default' => 's3']);

    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LogoS3');

    $this->withToken($adaToken)->putJson('/api/v1/orgs/'.$org->id.'/logo', [
        'logo' => UploadedFile::fake()->createWithContent('s.png', (string) file_get_contents(logoPng(80, 80))),
    ])->assertOk();

    $res = $this->get('/api/v1/public/orgs/'.$org->id.'/logo')->assertFound();
    expect((string) $res->headers->get('Location'))->toContain('logo.webp');
});

test('AC-006.8 delete clears storage and public access', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LogoDel');

    $this->withToken($adaToken)->putJson('/api/v1/orgs/'.$org->id.'/logo', [
        'logo' => UploadedFile::fake()->createWithContent('s.png', (string) file_get_contents(logoPng(60, 60))),
    ])->assertOk();

    $this->withToken($adaToken)->deleteJson('/api/v1/orgs/'.$org->id.'/logo')->assertNoContent();

    $org->refresh();
    expect($org->logo_path)->toBeNull();
    $this->get('/api/v1/public/orgs/'.$org->slug.'/logo')->assertNotFound();
});

/* ───────────────────────── legacy column removal ──────────────────────────── */

test('organizations no longer expose a free-text logo_url input', function () {
    [$ada, $adaToken] = Tenancy::user();
    $org = Tenancy::org($ada, 'LogoLegacy');

    $this->withToken($adaToken)->patchJson('/api/v1/orgs/'.$org->id, [
        'name' => 'Renamed', 'logo_url' => 'https://evil.example/x.png',
    ])->assertOk();

    expect(Organization::find($org->id)->logo_url)->toBeNull(); // column dropped; accessor-only
});
