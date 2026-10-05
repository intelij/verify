<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_documents', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('verification_id')->constrained()->cascadeOnDelete();
            $table->string('declared_type')->nullable();
            $table->string('detected_type')->nullable();
            $table->string('detected_country', 3)->nullable();
            $table->string('side', 10)->nullable();
            $table->string('storage_disk')->default('documents');
            $table->string('storage_path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->index();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->string('status')->default('uploaded')->index();
            $table->json('quality')->nullable();
            $table->decimal('quality_score', 4, 3)->nullable();
            $table->timestamps();

            $table->index(['verification_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_documents');
    }
};
