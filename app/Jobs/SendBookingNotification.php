<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\BookingNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Delivers one queued notification_logs row. Never throws: the outcome (sent / mocked / skipped / failed) is written to the row. */
class SendBookingNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $logId) {}

    public function handle(BookingNotifier $notifier): void
    {
        $log = NotificationLog::find($this->logId);

        if ($log?->status === NotificationLog::QUEUED) {
            $notifier->deliver($log);
        }
    }
}
