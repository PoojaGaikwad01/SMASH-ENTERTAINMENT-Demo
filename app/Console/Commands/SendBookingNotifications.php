<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Services\BookingNotifier;
use Illuminate\Console\Command;

/** Scheduled every 15 min (routes/console.php). Never sends the same message twice: any notification_logs row counts. */
class SendBookingNotifications extends Command
{
    protected $signature = 'bookings:send-notifications';

    protected $description = 'Queue booking reminders (REMINDER_HOURS_BEFORE the start) and thank-you messages (THANK_YOU_HOURS_AFTER the end)';

    public function handle(BookingNotifier $notifier): int
    {
        $reminders = $notifier->dueReminders()->each(fn ($b) => $notifier->notify($b, NotificationType::Reminder));
        $thanks = $notifier->dueThankYous()->each(fn ($b) => $notifier->notify($b, NotificationType::ThankYou));

        $this->info("Reminders queued for {$reminders->count()} booking(s); thank-you for {$thanks->count()}.");

        return self::SUCCESS;
    }
}
