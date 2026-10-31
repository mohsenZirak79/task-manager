<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', fn (Blueprint $table) => $table->unsignedInteger('duration_minutes')->nullable());
    }

    public function down(): void
    {
        Schema::table('meetings', fn (Blueprint $table) => $table->dropColumn('duration_minutes'));
    }
};
