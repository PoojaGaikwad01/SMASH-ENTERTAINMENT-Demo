<?php

namespace App\Http\Controllers;

use App\Enums\LeadSource;
use App\Models\Branch;
use App\Services\EnquiryIntake;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff-entered enquiries (phone call / walk-in) and the printable QR poster for the public walk-in form. */
class EnquiryController extends Controller
{
    public const STAFF_SOURCES = [LeadSource::PhoneCall, LeadSource::WalkIn];

    public function create(Request $request)
    {
        return view('enquiries.create', [
            'outlets' => Branch::active()->visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'city', 'slug']),
            'sources' => self::STAFF_SOURCES,
        ]);
    }

    public function store(Request $request, EnquiryIntake $intake)
    {
        $user = $request->user();
        $data = $request->validate(EnquiryIntake::rules() + [
            'source' => ['required', Rule::in(array_map(fn ($s) => $s->value, self::STAFF_SOURCES))],
            // Admin picks the outlet; outlet staff always create for their own outlet.
            'branch_id' => [$user->isAdmin() ? 'required' : 'nullable', Rule::exists('branches', 'id')->where('is_active', true)],
        ], ['branch_id.required' => 'Choose the outlet for this enquiry.']);

        $outlet = $user->isAdmin() ? Branch::find($data['branch_id']) : $user->branch;
        abort_unless($outlet?->is_active, 403, 'Your outlet is inactive.');

        [$lead, $merged] = $intake->submit($data, LeadSource::from($data['source']), $outlet, $user);

        return redirect()->route('inbox.show', $lead)->with('status', $merged
            ? 'This customer already has an open enquiry — your details were added to it.'
            : 'Enquiry created.');
    }

    /** Printable A4 poster with the QR code for /enquiry/{slug}. Admin: any outlet; staff: own outlet. */
    public function qr(Request $request, Branch $branch)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->branch_id === $branch->id, 403);

        $url = route('public-enquiry.show', $branch->slug);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd)))->writeString($url);

        return view('enquiries.qr', [
            'outlet' => $branch,
            'url' => $url,
            'svg' => preg_replace('/^<\?xml[^>]*>\s*/', '', $svg),
        ]);
    }
}
