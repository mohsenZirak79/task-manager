<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('child_role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('relation_type');
            $table->timestamps();
            $table->unique(['parent_role_id', 'child_role_id', 'relation_type'], 'role_relations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_relations');
    }
};
