<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->unsignedInteger('trial_days')->default(0);
            $table->boolean('active')->default(true);
            $table->json('entitlements');
            $table->timestamps();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->string('interval', 10);
            $table->unsignedBigInteger('amount'); // minor units — money is never float (spec 003)
            $table->string('gateway_price_id')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'currency', 'interval']);
        });

        Schema::create('billing_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('gateway', 20)->default('stripe');
            $table->string('gateway_customer_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('status', 16)->default('active');
            $table->string('gateway', 20)->default('stripe');
            $table->string('gateway_subscription_id')->nullable()->index();
            $table->string('interval', 10)->default('monthly');
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('past_due_since')->nullable();
            $table->timestamp('over_limit_until')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_invoice_id')->unique();
            $table->string('status', 16);
            $table->unsignedBigInteger('amount_due'); // minor units
            $table->char('currency', 3);
            $table->string('hosted_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('gateway_event_id')->unique();
            $table->string('type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('billing_customers');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
