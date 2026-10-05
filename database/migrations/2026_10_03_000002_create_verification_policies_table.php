<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('allowed_document_types');
            $table->json('required_fields');
            $table->json('optional_fields')->nullable();
            $table->decimal('verify_threshold', 4, 3)->default(0.90);
            $table->decimal('review_threshold', 4, 3)->default(0.70);
            $table->decimal('min_quality', 4, 3)->default(0.40);
            $table->decimal('min_extraction_confidence', 4, 3)->default(0.40);
            $table->boolean('require_not_expired')->default(false);
            $table->boolean('require_document_number')->default(false);
            $table->boolean('require_authenticity_pass')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_policies');
    }
};
