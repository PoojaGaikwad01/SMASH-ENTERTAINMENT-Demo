<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 4: price snapshot (catalogue prices can change later), who booked it, when it was confirmed, why it was cancelled.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('package_price', 10, 2)->default(0)->after('package_id');
            $table->foreignId('created_by_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('created_by_user_id');
            $table->string('cancel_reason')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['package_price', 'confirmed_at', 'cancel_reason']);
        });
    }
};
