<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumers', function (Blueprint $table) {
            $table->id();
            $table->string('key_id', 40)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('secret_hash');
            $table->string('webhook_secret')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->json('allowed_policies')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumers');
    }
};
