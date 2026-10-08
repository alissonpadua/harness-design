<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('logo_url');
            $table->string('logo_path')->nullable();
            $table->string('logo_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'logo_hash']);
            $table->string('logo_url')->nullable()->after('owner_id');
        });
    }
};
