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
        // The feature's pull/merge request, opened when the card moves to Review.
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('pull_request_number')->nullable()->after('feature_dir');
            $table->string('pull_request_url')->nullable()->after('pull_request_number');
            $table->string('pull_request_state')->nullable()->after('pull_request_url');
            $table->timestamp('merged_at')->nullable()->after('pull_request_state');
        });

        // Webhooks from GitHub/GitLab tell the app when a pull request is merged.
        Schema::table('projects', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('speckit_checked_at');
            $table->string('webhook_id')->nullable()->after('webhook_secret');
            $table->timestamp('webhook_last_received_at')->nullable()->after('webhook_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['pull_request_number', 'pull_request_url', 'pull_request_state', 'merged_at']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['webhook_secret', 'webhook_id', 'webhook_last_received_at']);
        });
    }
};
