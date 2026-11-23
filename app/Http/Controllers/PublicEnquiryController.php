<?php

namespace App\Http\Controllers;

use App\Enums\LeadSource;
use App\Models\Branch;
use App\Services\EnquiryIntake;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * No-login channels (PUBLIC_FORM_ENABLED): walk-in QR form /enquiry/{slug} and website API POST /api/enquiries.
 * Spam protection: honeypot field "website" (must stay empty; bots get a fake success) + throttle:public-enquiry.
 * Responses never reveal whether the phone already had an enquiry.
 */
class PublicEnquiryController extends Controller
{
    public const HONEYPOT = 'website';

    public function show(Branch $outlet)
    {
        $this->ensureOpen($outlet);

        return view('public.enquiry', ['outlet' => $outlet]);
    }

    public function store(Request $request, Branch $outlet, EnquiryIntake $intake)
    {
        $this->ensureOpen($outlet);

        if (! filled($request->input(self::HONEYPOT))) {
            $intake->submit($request->validate(EnquiryIntake::rules()), LeadSource::WalkInQr, $outlet);
        }

        return redirect()->route('public-enquiry.show', $outlet->slug)->with('submitted', true);
    }

    /** Website form. Body (JSON or form): name, phone, email?, enquiry_type?, preferred_date?, guest_count?, notes?, outlet? (slug), website (honeypot). */
    public function api(Request $request, EnquiryIntake $intake)
    {
        abort_unless(config('leads.public_form.enabled'), 404);

        if (! filled($request->input(self::HONEYPOT))) {
            $data = $request->validate(EnquiryIntake::rules() + [
                'outlet' => ['nullable', 'string', Rule::exists('branches', 'slug')->where('is_active', true)],
            ]);
            $outlet = filled($data['outlet'] ?? null) ? Branch::where('slug', $data['outlet'])->first() : null;
            $intake->submit($data, LeadSource::WebsiteForm, $outlet);
        }

        return response()->json(['ok' => true, 'message' => 'Thank you! Our team will contact you shortly.'], 201);
    }

    private function ensureOpen(Branch $outlet): void
    {
        abort_unless(config('leads.public_form.enabled') && $outlet->is_active, 404);
    }
}
