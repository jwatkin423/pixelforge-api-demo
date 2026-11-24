<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * All money columns are integer nanodollars (1 USD = 1,000,000,000).
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->unsignedBigInteger('estimated_min_charge_nanos');
            $table->unsignedBigInteger('estimated_max_charge_nanos');
            $table->unsignedBigInteger('provider_cost_nanos')->nullable();
            $table->unsignedBigInteger('charged_nanos')->nullable();

            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('status_detail')->nullable();

            $table->unsignedInteger('submission_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
