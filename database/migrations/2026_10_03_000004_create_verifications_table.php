<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('consumer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verification_subject_id')->constrained()->cascadeOnDelete();
            $table->string('policy_code')->index();
            $table->string('external_reference')->index();
            $table->string('idempotency_key')->nullable();
            $table->string('status')->default('pending')->index();
            $table->json('reason_codes')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('expected_document_type')->nullable();
            $table->string('callback_url', 512)->nullable();
            $table->timestamp('documents_due_at')->nullable();
            $table->timestamp('started_processing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_until')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();

            $table->unique(['consumer_id', 'idempotency_key']);
            $table->index(['consumer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};
