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
        // A ticket is one feature moving through the Spec Kit phases on the board.
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('description')->nullable();

            // Board column and what is happening in it.
            $table->string('stage');
            $table->string('status');

            // Set on the first run: e.g. branch "012-photo-albums", folder "specs/012-photo-albums".
            $table->unsignedInteger('feature_number')->nullable();
            $table->string('branch')->nullable();
            $table->string('feature_dir')->nullable();

            $table->string('model');
            $table->string('effort');
            $table->string('approval_mode');
            $table->boolean('skip_clarify')->default(false);
            $table->boolean('skip_analyze')->default(false);

            // The latest Claude session, resumed when someone replies to the agent.
            $table->string('session_id')->nullable();
            $table->unsignedBigInteger('last_run_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'stage']);
        });

        // The Spec Kit files of a ticket (spec.md, plan.md, tasks.md, ...), saved after each run.
        Schema::create('ticket_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->longText('content');
            $table->timestamps();

            $table->unique(['ticket_id', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_documents');
        Schema::dropIfExists('tickets');
    }
};
