<?php

namespace App\Console\Commands;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Services\ReminderService;
use Illuminate\Console\Command;

/** Scheduled every 30 min (routes/console.php). */
class SendLeadReminders extends Command
{
    protected $signature = 'leads:send-reminders';

    protected $description = 'Remind managers about leads assigned more than REMINDER_AFTER_MINUTES ago with no reply and no reminder in the last hour';

    public function handle(ReminderService $reminders): int
    {
        $reminded = 0;

        Lead::where('status', LeadStatus::New)
            ->whereNotNull('branch_id')
            ->whereNull('replied_at')
            ->whereNotIn('source', [LeadSource::PhoneCall, LeadSource::WalkIn]) // staff already spoke to them
            ->where('assigned_at', '<=', now()->subMinutes(config('leads.reminder_after_minutes')))
            ->whereDoesntHave('reminders', fn ($q) => $q->where('sent_at', '>', now()->subHour()))
            ->chunkById(100, function ($leads) use ($reminders, &$reminded) {
                foreach ($leads as $lead) {
                    $reminded += $reminders->remind($lead, automatic: true) > 0 ? 1 : 0;
                }
            });

        $this->info("Reminders sent for {$reminded} lead(s).");

        return self::SUCCESS;
    }
}
