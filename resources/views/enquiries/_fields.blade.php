{{-- Shared enquiry fields (staff form + public QR form). Validation: App\Services\EnquiryIntake::rules(). --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="name" class="label">Name *</label>
        <input id="name" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name" class="input">
    </div>
    <div>
        <label for="phone" class="label">Mobile number *</label>
        <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required maxlength="20" inputmode="tel" autocomplete="tel" placeholder="98xxxxxxxx" class="input">
    </div>
    <div>
        <label for="email" class="label">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="email" class="input">
    </div>
    <div>
        <label for="enquiry_type" class="label">Enquiry for</label>
        <select id="enquiry_type" name="enquiry_type" class="input">
            @foreach (\App\Enums\EnquiryType::cases() as $t)
                <option value="{{ $t->value }}" @selected(old('enquiry_type', 'general') === $t->value)>{{ $t->label() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="preferred_date" class="label">Preferred date</label>
        <input id="preferred_date" name="preferred_date" type="date" value="{{ old('preferred_date') }}" min="{{ now()->toDateString() }}" class="input">
    </div>
    <div>
        <label for="guest_count" class="label">Number of guests</label>
        <input id="guest_count" name="guest_count" type="number" value="{{ old('guest_count') }}" min="1" max="2000" inputmode="numeric" class="input">
    </div>
    <div class="sm:col-span-2">
        <label for="notes" class="label">{{ $notesLabel ?? 'Notes' }}</label>
        <textarea id="notes" name="notes" rows="3" maxlength="2000" class="input">{{ old('notes') }}</textarea>
    </div>
</div>
