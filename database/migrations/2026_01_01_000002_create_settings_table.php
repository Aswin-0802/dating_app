<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-editable configuration.
 *
 * Anything trust & safety should be able to change without a deploy — SLA
 * windows, risk factor weights, API rate limits — lives here, with
 * config/platform.php providing the fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();

            $table->enum('type', ['text', 'textarea', 'number', 'boolean', 'json', 'image', 'file'])
                ->default('text');

            $table->string('group')->default('general');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Whether the API's public /config endpoint may expose this value.
            $table->boolean('is_public')->default(false);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
