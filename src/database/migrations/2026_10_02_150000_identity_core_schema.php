<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('locale', 5)->default('en');
            $table->string('timezone', 64)->default('UTC');
            // FK added by spec 002 when organizations exists (ADR-0002).
            $table->unsignedBigInteger('current_organization_id')->nullable()->index();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_type', 20)->nullable()->after('name')->index();
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->text('user_agent')->nullable()->after('ip_address');
            // AC-001.26: device tokens never expire; integration tokens (006)
            // use Sanctum's existing nullable `expires_at` column.
        });

        Schema::create('oauth_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);             // google | facebook (AC-001.12)
            $table->string('provider_id');
            $table->string('provider_email')->nullable();
            $table->boolean('provider_email_verified')->default(false);
            $table->timestamps();
            $table->unique(['provider', 'provider_id']);
        });

        // Single-use signed payloads: verification, magic links, email-change (AC-001.2/.11/.21).
        Schema::create('auth_links', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->index();        // verify_email | magic_link | confirm_email_change
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();   // sha256 of the payload — raw never stored
            $table->string('email')->nullable();        // target address for email-change links
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_links');
        Schema::dropIfExists('oauth_accounts');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['device_type', 'ip_address', 'user_agent']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'deleted_at', 'two_factor_secret', 'two_factor_recovery_codes',
                'two_factor_confirmed_at', 'locale', 'timezone', 'current_organization_id',
            ]);
        });
    }
};
