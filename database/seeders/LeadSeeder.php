<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\EnquiryType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\EnquiryActivity;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;

/** 30 mock enquiries across all sources with short conversations. Runs only when the leads table is empty. */
class LeadSeeder extends Seeder
{
    private const NAMES = [
        'Aarav Sharma', 'Diya Kapoor', 'Vihaan Joshi', 'Ananya Iyer', 'Kabir Malhotra', 'Ishita Desai',
        'Reyansh Gupta', 'Myra Nair', 'Aditya Kulkarni', 'Saanvi Reddy', 'Arnav Bhatt', 'Kiara Shah',
        'Vivaan Menon', 'Anika Chopra', 'Rohan Pillai', 'Tara Banerjee', 'Dhruv Agarwal', 'Meera Krishnan',
        'Yash Jain', 'Nisha Verma', 'Karan Singh', 'Riya Saxena', 'Aryan Thakur', 'Sara Fernandes',
        'Neil D\'Souza', 'Avni Mishra', 'Om Patil', 'Zara Khan', 'Ishaan Rao', 'Prisha Mehta',
    ];

    private const CITIES = ['Mumbai', 'Pune', 'Thane', 'Navi Mumbai'];

    // Cycled per lead index. Confirmed/completed come from BookingSeeder (booking status drives the enquiry).
    private const STATUSES = ['new', 'contacted', 'new', 'quote_sent', 'new', 'contacted', 'lost', 'new'];

    private const STAFF_SOURCES = [LeadSource::PhoneCall, LeadSource::WalkIn, LeadSource::WalkInQr, LeadSource::WebsiteForm];

    private const CHATS = [
        [['in', 'Hi! Do you host birthday parties? My son turns 7 on 14th Dec.'], ['out', 'Hi! Yes we do 🎉 Party packages start at ₹14,999 for 10–20 kids. Shall I share the details?'], ['in', 'Yes please. Is Saturday evening free?']],
        [['in', 'What are your timings on weekends?'], ['out', 'We are open 11am–11pm on Saturday and Sunday.'], ['in', 'Great. Any combo for bowling + arcade?']],
        [['in', 'Hello, price for entry tickets for 4 adults this Sunday?'], ['out', 'Entry is ₹499 per person incl. ₹200 game credit.'], ['in', 'Ok thanks, will visit']],
        [['in', 'Can we book for a school outing? Around 40 kids'], ['in', 'Need it on a weekday next month'], ['out', 'Absolutely! Please share your preferred date and we will send a quote.']],
    ];

    public function run(): void
    {
        if (Lead::exists()) {
            return;
        }

        $admin = User::where('role', Role::Admin)->first();
        $branches = Branch::orderBy('id')->get();
        $staff = User::whereIn('role', Role::outletRoleValues())->get()->keyBy('branch_id');
        $sources = LeadSource::options();
        $types = EnquiryType::cases();

        foreach (self::NAMES as $i => $name) {
            $source = $sources[$i % count($sources)];
            $status = LeadStatus::from(self::STATUSES[$i % count(self::STATUSES)]);
            $type = $types[$i % count($types)];
            $at = now()->subHours((29 - $i) * 6 + 1)->subMinutes(($i * 7) % 50)->startOfMinute();
            $phone = '98'.sprintf('%08d', 45123000 + $i * 3171);
            $city = self::CITIES[$i % count(self::CITIES)];
            $staffEntered = in_array($source, self::STAFF_SOURCES, true);

            $lead = new Lead([
                'source' => $source, 'external_id' => "mock-{$source->value}-{$i}", 'city' => $city,
                'enquiry_type' => $type,
                'preferred_date' => in_array($type, [EnquiryType::General, EnquiryType::Other], true) ? null : $at->copy()->addDays(10 + $i)->toDateString(),
                'guest_count' => match ($type) {
                    EnquiryType::Birthday => 15 + $i % 10,
                    EnquiryType::SchoolGroup => 40,
                    EnquiryType::Corporate => 30,
                    EnquiryType::Tickets => 4,
                    default => null,
                },
            ]);

            match ($source) {
                LeadSource::FbLeadAd, LeadSource::IgLeadAd => $lead->fill([
                    'name' => $name, 'phone' => $phone,
                    'email' => strtolower(strtok($name, ' ')).$i.'@example.com',
                    'campaign_name' => $i % 2 ? 'Weekend Fun Pass – Nov' : 'Birthday Parties – Nov',
                    'ad_name' => $i % 2 ? 'Reel – Arena Tour' : 'Carousel – Party Packages',
                    'form_name' => $i % 2 ? 'Fun Pass Enquiry' : 'Party Enquiry Form',
                    'raw_payload' => ['id' => "mock-leadgen-{$i}", 'created_time' => $at->toIso8601String(), 'field_data' => [
                        ['name' => 'full_name', 'values' => [$name]],
                        ['name' => 'phone_number', 'values' => ['+91'.$phone]],
                        ['name' => 'city', 'values' => [$city]],
                        ['name' => 'number_of_guests', 'values' => [(string) (10 + $i)]],
                        ['name' => 'preferred_date', 'values' => [$at->copy()->addDays(10 + $i)->format('d M Y')]],
                    ]],
                ]),
                LeadSource::Messenger, LeadSource::IgDm => $lead->fill([
                    'name' => $name,
                    'phone' => $i % 4 === 0 ? $phone : null, // chats often have no phone yet
                    'contact_id' => ($source === LeadSource::Messenger ? 'psid-' : 'igsid-').(100000 + $i),
                    'raw_payload' => ['mock' => true],
                ]),
                LeadSource::WhatsappCustomer => $lead->fill([
                    'name' => $name, 'phone' => $phone, 'contact_id' => '91'.$phone, 'raw_payload' => ['mock' => true],
                ]),
                LeadSource::WhatsappAgency => $lead->fill([
                    'name' => $name, 'phone' => $phone,
                    'agency_name' => $i % 2 ? 'Happy Events Co.' : 'PartyWala Agency',
                    'raw_payload' => ['mock' => true, 'from' => $i % 2 ? '919900000001' : '919900000002'],
                ]),
                LeadSource::PhoneCall, LeadSource::WalkIn, LeadSource::WalkInQr, LeadSource::WebsiteForm => $lead->fill([
                    'name' => $name, 'phone' => $phone,
                    'email' => $i % 2 ? strtolower(strtok($name, ' ')).$i.'@example.com' : null,
                    'notes' => $type === EnquiryType::Birthday ? 'Wants a superhero theme, veg food only.' : null,
                ]),
            };

            // Legacy agency parsing (only when enabled): every other agency lead was unclear -> needs review.
            if ($source === LeadSource::WhatsappAgency && $i % 2 === 1) {
                $status = LeadStatus::NeedsReview;
                $lead->fill(['name' => null, 'phone' => null]);
            }

            // Meta enquiries arrive without an outlet (admin assigns); staff-entered ones belong to an outlet.
            $branch = $staffEntered || ! in_array($status, [LeadStatus::New, LeadStatus::NeedsReview], true) || $i % 3 === 0
                ? ($status === LeadStatus::NeedsReview ? null : $branches[$i % $branches->count()])
                : null;
            $creator = $staffEntered && in_array($source, [LeadSource::PhoneCall, LeadSource::WalkIn], true) ? $staff->get($branch?->id) : null;

            $lead->fill([
                'status' => $status,
                'branch_id' => $branch?->id,
                'assigned_by' => $branch && ! $staffEntered ? $admin?->id : null,
                'assigned_at' => $branch ? $at->copy()->addMinutes($staffEntered ? 0 : 15) : null,
                'created_by_user_id' => $creator?->id,
            ]);
            $lead->created_at = $lead->updated_at = $at;
            $lead->save();

            $this->seedMessages($lead, $i, $at, $staff->get($branch?->id), $name, $phone, $city);

            if (! in_array($status, [LeadStatus::New, LeadStatus::NeedsReview], true)) {
                $firstReply = $lead->messages()->where('direction', 'out')->min('created_at');
                $lead->forceFill(['replied_at' => $firstReply ?? $at->copy()->addMinutes(25 + ($i * 13) % 180)])->save();
            }

            $this->seedPipeline($lead, $i, $at, $staff->get($branch?->id));
        }
    }

    /** Lost reasons, call logs and follow-ups (some overdue, some today, some later) for the pipeline views. */
    private function seedPipeline(Lead $lead, int $i, $at, ?User $staff): void
    {
        if ($lead->status === LeadStatus::Lost) {
            $lead->forceFill(['lost_reason' => ['Price too high', 'Chose another venue', 'Date / slot not available'][$i % 3]])->saveQuietly();

            return;
        }

        if (in_array($lead->status, [LeadStatus::Contacted, LeadStatus::QuoteSent], true)) {
            EnquiryActivity::create([
                'lead_id' => $lead->id, 'user_id' => $staff?->id, 'type' => ActivityType::Call,
                'body' => $lead->status === LeadStatus::QuoteSent
                    ? 'Shared package prices on WhatsApp; customer is comparing options.'
                    : 'Spoke to the customer, they will confirm the date this week.',
                'created_at' => $at->copy()->addMinutes(45),
            ]);
        }

        if ($lead->status->isOpen() && $lead->branch_id) {
            $lead->forceFill(['next_follow_up_at' => match ($i % 4) {
                0 => now()->subHours(3),
                1 => now()->addHour(),
                2 => now()->addDay()->setTime(11, 0),
                default => null,
            }])->saveQuietly();
        }
    }

    private function seedMessages(Lead $lead, int $i, $at, ?User $staff, string $name, string $phone, string $city): void
    {
        $channel = $lead->source->channel();
        if ($channel === null) {
            return; // Lead Ads and staff/web forms have no chat thread.
        }

        if ($lead->source === LeadSource::WhatsappAgency) {
            $chat = [['in', $lead->status === LeadStatus::NeedsReview
                ? "Customer interested in party for 25 kids next month, please call after 6pm.\nFrom PartyWala"
                : "New lead from {$lead->agency_name}\nName: {$name}\nPhone: {$phone}\nCity: {$city}\nRequirement: Birthday party, ~25 kids"]];
        } else {
            $chat = self::CHATS[$i % count(self::CHATS)];
            // Only enquiries that were responded to have outbound messages; otherwise keep the inbound ones before any reply.
            if ($lead->status === LeadStatus::New) {
                $chat = collect($chat)->takeWhile(fn ($m) => $m[0] === 'in')->all();
            }
        }

        foreach (array_values($chat) as $k => [$dir, $body]) {
            Message::create([
                'lead_id' => $lead->id,
                'direction' => $dir,
                'channel' => $lead->source === LeadSource::WhatsappAgency ? 'agency' : $channel,
                'body' => $body,
                'external_message_id' => "mock-msg-{$i}-{$k}",
                'sent_by_user_id' => $dir === 'out' ? $staff?->id : null,
                'created_at' => $at->copy()->addMinutes($k * 9 + ($dir === 'out' ? 30 : 0)),
            ]);
        }
    }
}
