<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->default('device');
            $table->index(['organization_id', 'kind'], 'personal_access_tokens_organization_id_kind_index');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex('personal_access_tokens_organization_id_kind_index');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('kind');
        });
    }
};
