<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Our id doubles as the Idempotency-Key and metadata.generation_id we send to Pixelforge.
            $table->uuid('id')->primary();
            // Cost records outlive a deleted account, so the books still match Pixelforge's.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status');
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Money is integer nanodollars (1e-9 USD): exact, unlike floats or SQLite's "decimal".
            $table->unsignedBigInteger('estimated_cost');
            $table->unsignedBigInteger('max_cost');
            $table->unsignedBigInteger('provider_cost')->nullable();
            $table->unsignedBigInteger('cost')->nullable();

            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->unsignedTinyInteger('submission_errors')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']); // a user's history and credit holds
            $table->index(['status', 'completed_at']); // the spend report and the poller
        });

        Schema::create('pixelforge_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('received_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pixelforge_events');
        Schema::dropIfExists('generations');
    }
};
