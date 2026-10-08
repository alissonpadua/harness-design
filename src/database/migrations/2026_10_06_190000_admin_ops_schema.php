<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index('impersonator_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('deleted_at');
            $table->string('suspend_reason')->nullable()->after('suspended_at');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('admin_locked')->default(false)->after('over_limit_until');
        });

        Schema::create('organization_entitlement_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('overrides'); // subset of the 5 PlanEntitlementsData keys
            $table->timestamps();
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_subject_idx');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_causer_idx');
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_subject_idx');
            $table->dropIndex('activity_causer_idx');
            $table->dropIndex('activity_log_event_index');
        });
        Schema::dropIfExists('organization_entitlement_overrides');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('admin_locked');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspend_reason']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('personal_access_tokens_impersonator_id_index');
            $table->dropConstrainedForeignId('impersonator_id');
        });
    }
};
