<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 6: Razorpay Payment Links for booking advances. One booking can have several (a new link cancels the open one).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('provider_link_id', 40)->unique();   // plink_… (plink_mock_… in mock mode)
            $table->string('reference_id', 40);                   // {booking_code}-{n}, unique per Razorpay account
            $table->string('short_url');
            $table->decimal('amount', 10, 2);                     // requested, INR
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('status', 12)->default('created');     // created|paid|cancelled|expired
            $table->string('provider_payment_id', 40)->nullable()->unique(); // pay_… — idempotency guard
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
