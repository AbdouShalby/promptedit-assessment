<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();

            // Public id. Also the Idempotency-Key and metadata.local_id sent to Pixelforge.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider_id', 64)->nullable()->unique();   // gen_...
            $table->string('status', 20);

            // The request, exactly as sent.
            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 8);
            $table->json('reference_images');

            // All money is integer nanodollars (1 USD = 1,000,000,000).
            $table->unsignedInteger('markup_bps');
            $table->bigInteger('estimated_min_charge_nano');
            $table->bigInteger('estimated_max_charge_nano');
            $table->json('rates')->nullable();                           // provider rates, fixed at creation
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->bigInteger('provider_cost_nano')->nullable();        // null until final, 0 when failed
            $table->bigInteger('charge_nano')->nullable();               // null until final, 0 when failed

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            // Submission bookkeeping.
            $table->unsignedSmallInteger('submit_attempts')->default(0);       // 503 / timeout / unknown 5xx
            $table->unsignedSmallInteger('rate_limit_attempts')->default(0);   // 429 responses
            $table->boolean('submit_outcome_unknown')->default(false);         // a create call may have gone through
            $table->timestamp('last_checked_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->date('billing_date')->nullable();   // UTC day of provider completed_at; set only on success
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['status', 'last_checked_at']);
            $table->index(['billing_date', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
