<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10); // whatsapp|email
            $table->string('type', 15);    // confirmation|reminder|thank_you
            $table->string('status', 15);  // queued|sent|mocked|failed|skipped
            $table->text('provider_response')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['booking_id', 'type', 'channel']); // "never send twice"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
