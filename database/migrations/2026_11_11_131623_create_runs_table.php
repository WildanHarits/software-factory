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
        // One agent run: e.g. setting up Spec Kit, or one Spec Kit phase for a ticket (Phase 4).
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->index();
            $table->json('input')->nullable();

            $table->string('model')->nullable();
            $table->string('effort')->nullable();

            $table->string('base_branch')->nullable();
            $table->string('branch')->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->unsignedInteger('pull_request_number')->nullable();
            $table->string('pull_request_url')->nullable();

            $table->string('session_id')->nullable();
            $table->unsignedInteger('num_turns')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);

            $table->text('error')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
