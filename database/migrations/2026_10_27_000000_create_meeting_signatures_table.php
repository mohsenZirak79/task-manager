<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('content_version', 64);
            $table->json('content_snapshot');
            $table->timestamp('signed_at');
            $table->unique(['meeting_id', 'user_id', 'content_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_signatures');
    }
};
