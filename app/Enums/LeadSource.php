<?php

namespace App\Enums;

enum LeadSource: string
{
    case FbLeadAd = 'fb_lead_ad';
    case IgLeadAd = 'ig_lead_ad';
    case Messenger = 'messenger';
    case IgDm = 'ig_dm';
    case WhatsappCustomer = 'whatsapp_customer';
    case WhatsappAgency = 'whatsapp_agency'; // legacy: only when leads.agency_parsing is on
    case PhoneCall = 'phone_call';
    case WalkIn = 'walk_in';
    case WalkInQr = 'walk_in_qr';
    case WebsiteForm = 'website_form';

    public function label(): string
    {
        return match ($this) {
            self::FbLeadAd => 'Facebook Lead Ad',
            self::IgLeadAd => 'Instagram Lead Ad',
            self::Messenger => 'Messenger',
            self::IgDm => 'Instagram DM',
            self::WhatsappCustomer => 'WhatsApp',
            self::WhatsappAgency => 'Agency (WhatsApp)',
            self::PhoneCall => 'Phone call',
            self::WalkIn => 'Walk-in',
            self::WalkInQr => 'Walk-in (QR form)',
            self::WebsiteForm => 'Website form',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::FbLeadAd => 'FB Ad',
            self::IgLeadAd => 'IG Ad',
            self::Messenger => 'Messenger',
            self::IgDm => 'IG DM',
            self::WhatsappCustomer => 'WhatsApp',
            self::WhatsappAgency => 'Agency',
            self::PhoneCall => 'Call',
            self::WalkIn => 'Walk-in',
            self::WalkInQr => 'QR form',
            self::WebsiteForm => 'Website',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FbLeadAd => 'bg-blue-100 text-blue-700',
            self::IgLeadAd => 'bg-pink-100 text-pink-700',
            self::Messenger => 'bg-sky-100 text-sky-700',
            self::IgDm => 'bg-fuchsia-100 text-fuchsia-700',
            self::WhatsappCustomer => 'bg-green-100 text-green-700',
            self::WhatsappAgency => 'bg-teal-100 text-teal-700',
            self::PhoneCall => 'bg-indigo-100 text-indigo-700',
            self::WalkIn => 'bg-orange-100 text-orange-700',
            self::WalkInQr => 'bg-amber-100 text-amber-800',
            self::WebsiteForm => 'bg-slate-200 text-slate-700',
        };
    }

    /** Messaging channel used for message rows / replies; null = no chat thread (Lead Ads, staff/web forms). */
    public function channel(): ?string
    {
        return match ($this) {
            self::FbLeadAd, self::IgLeadAd, self::PhoneCall, self::WalkIn, self::WalkInQr, self::WebsiteForm => null,
            self::Messenger => 'messenger',
            self::IgDm => 'instagram',
            self::WhatsappCustomer, self::WhatsappAgency => 'whatsapp',
        };
    }

    public function isLeadAd(): bool
    {
        return $this === self::FbLeadAd || $this === self::IgLeadAd;
    }

    /** Entered by staff who already spoke to the customer (no "awaiting reply" reminders). */
    public function isStaffEntered(): bool
    {
        return $this === self::PhoneCall || $this === self::WalkIn;
    }

    /** Submitted by the customer through our own forms (QR walk-in form / website). */
    public function isWebForm(): bool
    {
        return $this === self::WalkInQr || $this === self::WebsiteForm;
    }

    /** Sources offered in filters (agency only while agency parsing is on). */
    public static function options(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s !== self::WhatsappAgency || config('leads.agency_parsing')));
    }
}
