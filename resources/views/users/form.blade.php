@php($self = $user->exists && $user->is(auth()->user()))
<x-layouts.app :title="$user->exists ? 'Edit user' : 'New user'">
    <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}"
              x-data="{ role: @js(old('role', $user->role?->value)) }" class="card space-y-4 p-5">
            @csrf
            @if ($user->exists) @method('PUT') @endif
            <div>
                <label for="name" class="label">Name</label>
                <input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="100" class="input">
            </div>
            <div>
                <label for="email" class="label">Email (login)</label>
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="150" class="input">
            </div>
            <div>
                <label for="phone" class="label">Phone</label>
                <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" placeholder="+91 98xxxxxxxx" class="input">
            </div>

            @if ($self)
                <input type="hidden" name="role" value="admin">
                <input type="hidden" name="is_active" value="1">
                <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">This is your account — role and status can't be changed here.</p>
            @else
                <div>
                    <label for="role" class="label">Role</label>
                    <select id="role" name="role" x-model="role" required class="input">
                        @foreach (\App\Enums\Role::cases() as $role)
                            <option value="{{ $role->value }}">{{ $role->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div x-show="role !== 'admin'">
                    <label for="branch_id" class="label">Outlet</label>
                    <select id="branch_id" name="branch_id" class="input" :required="role !== 'admin'">
                        <option value="">Select outlet…</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected(old('branch_id', $user->branch_id) == $b->id)>{{ $b->name }} ({{ $b->city }}){{ $b->is_active ? '' : ' — inactive' }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active)) class="rounded border-slate-300 text-orange-600"> Active (can log in)
                </label>
            @endif

            @unless ($user->exists)
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="label">Password</label>
                        <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" class="input">
                    </div>
                    <div>
                        <label for="password_confirmation" class="label">Confirm password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" class="input">
                    </div>
                </div>
            @endunless

            <div class="flex gap-2">
                <button class="btn btn-primary">Save</button>
                <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>

        @if ($user->exists)
            <div class="space-y-6">
                <form method="POST" action="{{ route('users.password', $user) }}" class="card space-y-4 p-5">
                    @csrf @method('PUT')
                    <h2 class="font-semibold">Reset password</h2>
                    <div>
                        <label for="new_password" class="label">New password</label>
                        <input id="new_password" name="password" type="password" required minlength="8" autocomplete="new-password" class="input">
                    </div>
                    <div>
                        <label for="new_password_confirmation" class="label">Confirm new password</label>
                        <input id="new_password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" class="input">
                    </div>
                    <button class="btn btn-secondary">Set new password</button>
                </form>

                @unless ($self)
                    <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Delete this user permanently?')" class="card flex items-center justify-between gap-3 p-5">
                        @csrf @method('DELETE')
                        <p class="text-sm text-slate-600">Prefer deactivating to keep history.</p>
                        <button class="btn btn-danger shrink-0">Delete</button>
                    </form>
                @endunless
            </div>
        @endif
    </div>
</x-layouts.app>
