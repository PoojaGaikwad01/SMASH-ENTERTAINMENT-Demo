<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BookingCalendarController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\EnquiryPipelineController;
use App\Http\Controllers\PublicEnquiryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentLinkController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public walk-in enquiry form (QR poster at the outlet). No login; honeypot + rate limit (PublicEnquiryController).
Route::get('enquiry/{outlet:slug}', [PublicEnquiryController::class, 'show'])->name('public-enquiry.show');
Route::post('enquiry/{outlet:slug}', [PublicEnquiryController::class, 'store'])->middleware('throttle:public-enquiry')->name('public-enquiry.store');

// No public registration. Password resets are done by an admin.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', fn () => redirect()->route('inbox'));
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
    Route::get('inbox/{lead}', [InboxController::class, 'show'])->whereNumber('lead')->name('inbox.show');
    Route::get('attachments/{message}', [AttachmentController::class, 'show'])->name('attachments.show');

    // Staff-entered enquiries (phone call / walk-in) + printable QR poster per outlet.
    Route::get('enquiries/create', [EnquiryController::class, 'create'])->name('enquiries.create');
    Route::post('enquiries', [EnquiryController::class, 'store'])->middleware('throttle:60,1')->name('enquiries.store');
    Route::get('branches/{branch}/qr', [EnquiryController::class, 'qr'])->name('branches.qr');

    // Reply actions: LeadPolicy::reply (admin or the lead's branch manager).
    Route::post('leads/{lead}/reply', [LeadController::class, 'reply'])->middleware('throttle:30,1')->name('leads.reply');
    Route::post('leads/{lead}/mark-replied', [LeadController::class, 'markReplied'])->name('leads.mark-replied');
    // Pipeline (LeadPolicy::update): status buttons (lost needs a reason), call/note log, follow-up.
    Route::post('leads/{lead}/status', [EnquiryPipelineController::class, 'status'])->name('leads.status');
    Route::post('leads/{lead}/activities', [EnquiryPipelineController::class, 'activity'])->name('leads.activities.store');
    Route::patch('leads/{lead}/follow-up', [EnquiryPipelineController::class, 'followUp'])->name('leads.follow-up');
    Route::patch('leads/{lead}', [LeadController::class, 'update'])->name('leads.update'); // LeadPolicy::update

    // Bookings: created from an enquiry (LeadPolicy::update), then BookingPolicy (admin or own outlet). Writes go through BookingService.
    Route::get('leads/{lead}/bookings/create', [BookingController::class, 'create'])->name('bookings.create');
    Route::post('leads/{lead}/bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('bookings/calendar', [BookingCalendarController::class, 'calendar'])->name('bookings.calendar');
    Route::get('bookings/lookup', [BookingCalendarController::class, 'lookup'])->name('bookings.lookup');
    Route::get('bookings/availability', [BookingCalendarController::class, 'availability'])->name('bookings.availability');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::get('bookings/{booking}/edit', [BookingController::class, 'edit'])->name('bookings.edit');
    Route::put('bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');
    Route::post('bookings/{booking}/status', [BookingController::class, 'status'])->name('bookings.status');
    Route::post('bookings/{booking}/notifications', [BookingController::class, 'notify'])->middleware('throttle:20,1')->name('bookings.notify'); // Resend
    // Razorpay advance links (PAYMENTS_ENABLED, else 404). Mock-paid only in APP_MODE=mock.
    Route::post('bookings/{booking}/payment-links', [PaymentLinkController::class, 'store'])->middleware('throttle:20,1')->name('payment-links.store');
    Route::post('payment-links/{link}/mock-paid', [PaymentLinkController::class, 'mockPaid'])->name('payment-links.mock-paid');

    // Catalogue (areas / packages / add-ons): admin, or outlet manager for their own outlet.
    Route::prefix('catalog/{type}')->name('catalog.')->middleware('can:manage-catalog')
        ->where(['type' => implode('|', array_keys(CatalogController::TYPES))])
        ->controller(CatalogController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{id}/edit', 'edit')->name('edit');
            Route::put('{id}', 'update')->name('update');
            Route::delete('{id}', 'destroy')->name('destroy');
        });

    Route::get('reports', [ReportController::class, 'index'])->name('reports');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

    // FCM device registration (mobile app / web push)
    Route::post('device-tokens', [DeviceTokenController::class, 'store'])->name('device-tokens.store');
    Route::delete('device-tokens', [DeviceTokenController::class, 'destroy'])->name('device-tokens.destroy');

    // In-app notification bell
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.count');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications/{id}', [NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');

    // Admin only: outlet assignment, users, outlets. Outlet staff are scoped to their own outlet elsewhere.
    Route::middleware('role:admin')->group(function () {
        Route::post('leads/bulk-assign', [LeadController::class, 'bulkAssign'])->name('leads.bulk-assign');
        Route::post('leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
        Route::post('leads/{lead}/remind', [LeadController::class, 'remind'])->name('leads.remind');

        Route::resource('branches', BranchController::class)->except('show');
        Route::post('branches/{branch}/managers', [BranchController::class, 'assignManager'])->name('branches.managers.store');

        Route::resource('users', UserController::class)->except('show');
        Route::put('users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
        Route::patch('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
    });
});
