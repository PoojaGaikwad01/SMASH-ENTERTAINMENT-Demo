<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Funky Monkey -> Smash: branch_manager -> outlet_manager; lead statuses -> enquiry pipeline.
// needs_review stays (legacy agency parsing only).
return new class extends Migration
{
    private const STATUS_MAP = ['assigned' => 'new', 'reply_sent' => 'contacted', 'closed' => 'lost'];

    public function up(): void
    {
        DB::table('users')->where('role', 'branch_manager')->update(['role' => 'outlet_manager']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('front_desk')->change(); // admin|outlet_manager|front_desk
        });

        foreach (self::STATUS_MAP as $old => $new) {
            DB::table('leads')->where('status', $old)->update(['status' => $new]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('branch_manager')->change();
        });

        DB::table('users')->whereIn('role', ['outlet_manager', 'front_desk'])->update(['role' => 'branch_manager']);

        // Best effort: statuses without a legacy equivalent collapse to the closest one.
        DB::table('leads')->whereNotNull('branch_id')->where('status', 'new')->update(['status' => 'assigned']);
        DB::table('leads')->whereIn('status', ['contacted', 'quote_sent', 'confirmed'])->update(['status' => 'reply_sent']);
        DB::table('leads')->whereIn('status', ['completed', 'lost'])->update(['status' => 'closed']);
    }
};
