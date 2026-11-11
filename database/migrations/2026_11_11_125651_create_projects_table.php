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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            // The git repository this project works on.
            $table->string('provider');
            $table->string('repo_full_name');
            $table->string('repo_external_id');
            $table->string('repo_web_url');
            $table->string('repo_clone_url');
            $table->string('default_branch');
            $table->boolean('repo_private')->default(true);

            $table->foreignId('git_credential_id')->nullable()->constrained('credentials')->nullOnDelete();
            $table->foreignId('ai_credential_id')->nullable()->constrained('credentials')->nullOnDelete();

            // Defaults that each ticket starts with.
            $table->string('default_model');
            $table->string('default_effort');
            $table->string('approval_mode');
            $table->string('merge_policy');

            $table->boolean('speckit_initialized')->default(false);
            $table->timestamp('speckit_checked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
