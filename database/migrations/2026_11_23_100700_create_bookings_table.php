<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 20)->unique(); // SMB-YYMMDD-NNNN
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('event_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('guest_count');
            $table->string('child_name', 100)->nullable();
            $table->unsignedTinyInteger('child_age')->nullable();
            $table->text('special_requests')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('advance_paid', 10, 2)->default(0);
            $table->decimal('balance_due', 10, 2)->default(0);
            $table->string('payment_status', 10)->default('unpaid'); // unpaid|partial|paid
            $table->string('status', 12)->default('tentative'); // tentative|confirmed|completed|cancelled
            $table->timestamps();

            $table->index(['area_id', 'event_date', 'status']); // double-booking check
            $table->index(['outlet_id', 'event_date']);         // calendar / counter lookup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
