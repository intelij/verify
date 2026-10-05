<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_field_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->string('expected_masked')->nullable();
            $table->boolean('provided')->default(true);
            $table->boolean('match')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('reason_code')->nullable();
            $table->timestamps();

            $table->index(['verification_id']);
        });

        Schema::create('verification_events', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('verification_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('actor_type')->default('system');
            $table->string('actor_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['verification_id', 'created_at']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('verification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consumer_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('url', 512);
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(8);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('verification_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('provider_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('verification_document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('operation');
            $table->string('status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_attempts');
        Schema::dropIfExists('verification_reviews');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('verification_events');
        Schema::dropIfExists('verification_field_results');
    }
};
