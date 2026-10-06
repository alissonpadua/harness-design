<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // catalog type string (class not needed)
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable(); // unused (locked scope) — kept for framework compat
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->boolean('email_enabled');
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });

        Schema::create('failed_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->string('recipient_type', 40); // user | email
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('recipient_email')->nullable();
            $table->json('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error');
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('failed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_notifications');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
