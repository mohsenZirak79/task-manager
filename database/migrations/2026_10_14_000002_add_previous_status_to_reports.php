<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reports', 'previous_status')) {
            Schema::table('reports', function (Blueprint $table): void {
                $table->string('previous_status', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('reports')->where('status', 'inactive')->exists()) {
            throw new RuntimeException('Cannot roll back while inactive reports exist.');
        }
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('previous_status');
        });
    }
};
