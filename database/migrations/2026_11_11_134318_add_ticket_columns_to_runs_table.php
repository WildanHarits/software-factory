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
        Schema::table('runs', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('project_id')->constrained()->cascadeOnDelete();
            // The agent's final message: clarify questions, the analysis report, a summary of changes.
            $table->longText('result_text')->nullable()->after('error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
            $table->dropColumn('result_text');
        });
    }
};
