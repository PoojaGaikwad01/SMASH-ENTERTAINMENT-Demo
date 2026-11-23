<x-layouts.app :title="$branch->exists ? 'Edit outlet' : 'New outlet'">
    <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ $branch->exists ? route('branches.update', $branch) : route('branches.store') }}" class="card space-y-4 p-5">
            @csrf
            @if ($branch->exists) @method('PUT') @endif
            <div>
                <label for="name" class="label">Outlet name</label>
                <input id="name" name="name" value="{{ old('name', $branch->name) }}" required maxlength="100" class="input">
            </div>
            <div>
                <label for="slug" class="label">URL slug <span class="font-normal text-slate-400">(public enquiry form: /enquiry/slug)</span></label>
                <input id="slug" name="slug" value="{{ old('slug', $branch->slug) }}" maxlength="60" pattern="[A-Za-z0-9_\-]+" placeholder="auto from name" class="input">
            </div>
            <div>
                <label for="city" class="label">City</label>
                <input id="city" name="city" value="{{ old('city', $branch->city) }}" required maxlength="100" class="input">
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $branch->is_active)) class="rounded border-slate-300 text-orange-600"> Active
            </label>
            <div class="flex gap-2">
                <button class="btn btn-primary">Save</button>
                <a href="{{ route('branches.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>

        @if ($branch->exists)
            <div class="space-y-6">
                <div class="card p-5">
                    <h2 class="mb-3 font-semibold">Outlet staff</h2>
                    <ul class="mb-4 divide-y divide-slate-100 text-sm">
                        @forelse ($branch->staff as $manager)
                            <li class="flex items-center justify-between py-2">
                                <span>{{ $manager->name }} <span class="text-xs text-slate-500">· {{ $manager->role->label() }}</span> @unless ($manager->is_active)<span class="badge bg-slate-100 text-slate-500">Inactive</span>@endunless</span>
                                <a href="{{ route('users.edit', $manager) }}" class="text-orange-700 hover:underline">Edit</a>
                            </li>
                        @empty
                            <li class="py-2 text-slate-500">No staff assigned.</li>
                        @endforelse
                    </ul>

                    @if ($availableManagers->isNotEmpty())
                        <form method="POST" action="{{ route('branches.managers.store', $branch) }}" class="flex flex-col gap-2 sm:flex-row">
                            @csrf
                            <select name="user_id" required class="input">
                                <option value="">Assign staff…</option>
                                @foreach ($availableManagers as $m)
                                    <option value="{{ $m->id }}">{{ $m->name }} ({{ $m->role->label() }}) — {{ $m->branch?->name ? 'moves from '.$m->branch->name : 'unassigned' }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-secondary shrink-0">Assign</button>
                        </form>
                    @endif
                    <p class="mt-2 text-xs text-slate-500">New staff member? <a href="{{ route('users.create') }}" class="text-orange-700 hover:underline">Create a user</a> with role Outlet Manager or Front Desk.</p>
                </div>

                <form method="POST" action="{{ route('branches.destroy', $branch) }}" onsubmit="return confirm('Delete this outlet?')" class="card flex items-center justify-between gap-3 p-5">
                    @csrf @method('DELETE')
                    <p class="text-sm text-slate-600">Delete only if unused. Prefer deactivating.</p>
                    <button class="btn btn-danger shrink-0">Delete</button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
