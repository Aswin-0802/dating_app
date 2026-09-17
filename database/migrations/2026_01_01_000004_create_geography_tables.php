<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->char('iso2', 2)->unique();
            $table->string('dial_code', 8)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone')->default('UTC');

            // Focus cities carry most of the member base, so the marketplace
            // health screens have enough signal per city to be meaningful.
            $table->boolean('is_focus')->default(false);

            $table->index(['country_id', 'name']);
            $table->index('is_focus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('countries');
    }
};
