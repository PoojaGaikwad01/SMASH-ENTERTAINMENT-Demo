<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// "branches" = outlets in the UI. slug is used by the public walk-in form URL (/enquiry/{slug}).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('slug', 60)->nullable()->after('name');
        });

        foreach (DB::table('branches')->get(['id', 'name']) as $b) {
            DB::table('branches')->where('id', $b->id)->update(['slug' => Str::slug($b->name).'-'.$b->id]);
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
