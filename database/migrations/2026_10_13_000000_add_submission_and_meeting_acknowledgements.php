<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('submission_key', 100)->nullable();
            $table->unique(['created_by', 'submission_key']);
        });
        Schema::table('meetings', fn (Blueprint $table) => $table->timestamp('started_at')->nullable());
        Schema::create('meeting_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->unique(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_acknowledgements');
        Schema::table('meetings', fn (Blueprint $table) => $table->dropColumn('started_at'));
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropUnique(['created_by', 'submission_key']);
            $table->dropColumn('submission_key');
        });
    }
};
