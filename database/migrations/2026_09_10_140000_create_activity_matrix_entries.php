<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_matrix_scopes', function (Blueprint $table): void {
            $table->string('scope', 100)->primary();
        });
        Schema::create('activity_matrix_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->string('event_key', 191)->unique();
            $table->string('actor_id', 100)->nullable();
            $table->string('actor_label', 128);
            $table->string('operation', 128);
            $table->string('category', 40);
            $table->string('status', 80);
            $table->string('summary', 240);
            $table->string('correlation_id', 100)->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->json('details');
            $table->index(['scope', 'id']);
            $table->index(['scope', 'actor_id', 'id']);
            $table->index(['scope', 'operation', 'id']);
            $table->index(['scope', 'occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_matrix_entries');
        Schema::dropIfExists('activity_matrix_scopes');
    }
};
