<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_relation_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission_type');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['from_user_id', 'to_user_id', 'permission_type'], 'user_relation_exceptions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_relation_exceptions');
    }
};
