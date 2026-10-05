<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('meeting_resolution_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('reports')->whereNull('meeting_resolution_id')->exists()) {
            throw new RuntimeException('Cannot roll back while standalone reports exist.');
        }
        Schema::table('reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('meeting_resolution_id')->nullable(false)->change();
        });
    }
};
