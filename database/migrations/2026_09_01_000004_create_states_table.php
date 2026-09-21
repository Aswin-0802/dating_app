<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * States, provinces and regions, between country and city.
 *
 * Optional by design: a member in Singapore has no state, and a country added
 * later starts with none. Where they do exist they matter — "Tamil Nadu" says
 * far more about where somebody is than a single city name does, and it is
 * how people describe where they live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('code', 10)->nullable();   // TN, CA, NSW
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['country_id', 'name']);
            $table->index(['country_id', 'is_active']);
        });

        Schema::table('cities', function (Blueprint $table): void {
            $table->foreignId('state_id')->nullable()->after('country_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('state_id');
        });

        Schema::dropIfExists('states');
    }
};
