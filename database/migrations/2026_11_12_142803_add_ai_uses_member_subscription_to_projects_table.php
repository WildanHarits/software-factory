<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Runs use the Claude subscription of whoever starts them instead of ai_credential_id.
            $table->boolean('ai_uses_member_subscription')->default(false)->after('ai_credential_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('ai_uses_member_subscription');
        });
    }
};
