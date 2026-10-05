<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('person');
            $table->string('external_id')->index();
            $table->string('first_name');
            $table->string('middle_names')->nullable();
            $table->string('surname');
            $table->date('date_of_birth')->nullable();
            $table->string('country', 3)->nullable();
            $table->string('national_id_number')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('driver_license_number')->nullable();
            $table->string('gender', 20)->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();

            $table->index(['consumer_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_subjects');
    }
};
