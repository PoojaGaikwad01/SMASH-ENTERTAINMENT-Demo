<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// leads = enquiries.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('enquiry_type', 20)->default('general')->after('status'); // App\Enums\EnquiryType
            $table->date('preferred_date')->nullable()->after('enquiry_type');
            $table->unsignedSmallInteger('guest_count')->nullable()->after('preferred_date');
            $table->text('notes')->nullable()->after('guest_count');
            $table->string('lost_reason')->nullable()->after('notes');
            $table->timestamp('next_follow_up_at')->nullable()->after('lost_reason');
            $table->foreignId('created_by_user_id')->nullable()->after('next_follow_up_at')->constrained('users')->nullOnDelete();

            $table->index('enquiry_type');
            $table->index('next_follow_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropIndex(['enquiry_type']);
            $table->dropIndex(['next_follow_up_at']);
            $table->dropColumn(['enquiry_type', 'preferred_date', 'guest_count', 'notes', 'lost_reason', 'next_follow_up_at']);
        });
    }
};
