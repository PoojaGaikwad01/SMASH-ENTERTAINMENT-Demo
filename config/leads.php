<?php

// All enquiry/booking settings. Read via config('leads.*'), never env() in app code.
return [

    // mock = no real Meta/WhatsApp/FCM/AI/email/payment calls (log instead); live = real APIs.
    'mode' => env('APP_MODE', 'mock'),

    'outlet_default_timezone' => env('OUTLET_DEFAULT_TIMEZONE', 'Asia/Kolkata'),

    'dedupe_days' => (int) env('DEDUPE_DAYS', 7),
    'reminder_after_minutes' => (int) env('REMINDER_AFTER_MINUTES', 60),
    'follow_up_default_hours' => (int) env('FOLLOW_UP_DEFAULT_HOURS', 24),

    // Public walk-in (/enquiry/{outlet}) and website (POST /api/enquiries) forms.
    'public_form' => [
        'enabled' => (bool) env('PUBLIC_FORM_ENABLED', true),
    ],

    'bookings' => [
        'reminder_hours_before' => (int) env('REMINDER_HOURS_BEFORE', 24),
        'thank_you_hours_after' => (int) env('THANK_YOU_HOURS_AFTER', 3),
    ],

    // Booking hand-off to the POS / Semnox (App\Services\BookingExport). none = no-op; csv = files in storage/app/private/{path}.
    'booking_export' => [
        'driver' => env('BOOKING_EXPORT_DRIVER', 'none'),
        'path' => env('BOOKING_EXPORT_PATH', 'exports'),
    ],

    'payments' => [
        'enabled' => (bool) env('PAYMENTS_ENABLED', false),
        // Suggested advance = this % of the booking total (minus what's already paid); staff can change it per link.
        'advance_percent' => (int) env('PAYMENT_ADVANCE_PERCENT', 50),
        'link_expiry_hours' => (int) env('PAYMENT_LINK_EXPIRY_HOURS', 48),
        'razorpay' => [
            'key_id' => env('RAZORPAY_KEY_ID'),
            'key_secret' => env('RAZORPAY_KEY_SECRET'),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        ],
    ],

    // Legacy Funky Monkey agency parsing (WhatsApp forwards from agencies). Off = agency numbers are treated as customers.
    'agency_parsing' => (bool) env('AGENCY_PARSING_ENABLED', false),

    // WhatsApp senders treated as agencies (e.g. 919876543210, 9876543210 or +14155551234). Compared via Phone::normalize().
    'agency_numbers' => array_values(array_filter(array_map(
        fn ($n) => preg_replace('/[^\d+]/', '', $n),
        explode(',', (string) env('AGENCY_NUMBERS', ''))
    ))),

    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
        'ig_account_id' => env('IG_ACCOUNT_ID'),
    ],

    'whatsapp' => [
        'phone_number_id' => env('WA_PHONE_NUMBER_ID'),
        'access_token' => env('WA_ACCESS_TOKEN'),
        // Approved template names for booking notifications.
        'templates' => [
            'confirmation' => env('WA_TEMPLATE_CONFIRMATION'),
            'reminder' => env('WA_TEMPLATE_REMINDER'),
            'thank_you' => env('WA_TEMPLATE_THANK_YOU'),
            'payment_link' => env('WA_TEMPLATE_PAYMENT_LINK'),
        ],
        'template_lang' => env('WA_TEMPLATE_LANG', 'en'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'none'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL'),
    ],

    'firebase' => [
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
    ],
];
