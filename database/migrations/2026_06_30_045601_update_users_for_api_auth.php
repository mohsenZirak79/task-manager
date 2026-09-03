<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('org_code')->nullable()->unique()->after('id');
            $table->string('first_name', 100)->nullable()->after('org_code');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('mobile', 20)->nullable()->unique()->after('last_name');
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->date('birth_date')->nullable()->after('password');
            $table->string('internal_phone', 50)->nullable()->after('birth_date');
            $table->unsignedBigInteger('avatar_file_id')->nullable()->after('internal_phone');
            $table->unsignedBigInteger('signature_file_id')->nullable()->after('avatar_file_id');
            $table->string('title')->nullable()->after('signature_file_id');
            $table->boolean('is_active')->default(true)->after('title');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
            $table->foreignId('created_by')->nullable()->after('last_login_at')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });

        DB::table('users')->orderBy('id')->get(['id', 'name', 'email'])->each(function ($user): void {
            $parts = preg_split('/\s+/', trim((string) $user->name), 2) ?: [];

            DB::table('users')->where('id', $user->id)->update([
                'org_code' => (string) (100000 + (int) $user->id),
                'first_name' => $parts[0] ?: 'User',
                'last_name' => $parts[1] ?? 'User',
                'mobile' => 'user-'.$user->id,
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('org_code')->nullable(false)->change();
            $table->string('first_name', 100)->nullable(false)->change();
            $table->string('last_name', 100)->nullable(false)->change();
            $table->string('mobile', 20)->nullable(false)->change();
            $table->dropColumn(['name', 'email_verified_at', 'remember_token']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn([
                'org_code',
                'first_name',
                'last_name',
                'mobile',
                'birth_date',
                'internal_phone',
                'avatar_file_id',
                'signature_file_id',
                'title',
                'is_active',
                'must_change_password',
                'last_login_at',
                'created_by',
                'updated_by',
                'deleted_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('name');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
