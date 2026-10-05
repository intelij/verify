<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_document_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_version')->nullable();
            $table->string('document_type')->nullable();
            $table->string('country', 3)->nullable();
            $table->json('fields')->nullable();
            $table->json('field_confidences')->nullable();
            $table->decimal('extraction_confidence', 4, 3)->nullable();
            $table->json('signals')->nullable();
            $table->text('raw_text_encrypted')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['verification_document_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
