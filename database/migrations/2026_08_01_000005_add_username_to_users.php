<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
            $table->unique('username');
            $table->string('email')->nullable()->change();
        });
        DB::table('users')->orderBy('id')->each(function ($user) {
            $base = Str::of((string) ($user->email ?: $user->name))->before('@')->slug('_')->lower()->limit(40, '')->value() ?: 'operator';
            DB::table('users')->where('id', $user->id)->update(['username' => $base.'_'.$user->id]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
