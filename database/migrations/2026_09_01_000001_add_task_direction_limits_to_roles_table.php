<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->unsignedTinyInteger('request_up_levels')->nullable()->after('visible_tabs');
            $table->unsignedTinyInteger('assignment_down_levels')->nullable()->after('request_up_levels');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['request_up_levels', 'assignment_down_levels']);
        });
    }
};
