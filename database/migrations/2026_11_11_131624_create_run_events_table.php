<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The step-by-step log of a run, shown live on the run page.
        Schema::create('run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('kind');
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'seq']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('run_events');
    }
};
