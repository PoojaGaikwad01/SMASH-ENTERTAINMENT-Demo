<x-layouts.app title="Users">
    <x-slot:actions>
        <a href="{{ route('users.create') }}" class="btn btn-primary">+ New user</a>
    </x-slot:actions>

    <form method="GET" class="mb-4 grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
        <input name="q" value="{{ request('q') }}" placeholder="Search name, email, phone" class="input">
        <select name="role" class="input">
            <option value="">All roles</option>
            @foreach (\App\Enums\Role::cases() as $role)
                <option value="{{ $role->value }}" @selected(request('role') === $role->value)>{{ $role->label() }}</option>
            @endforeach
        </select>
        <select name="branch_id" class="input">
            <option value="">All outlets</option>
            @foreach ($branches as $b)
                <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
        <button class="btn btn-secondary">Filter</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr><th>User</th><th class="hidden sm:table-cell">Phone</th><th>Role / Outlet</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>
                            <div class="font-medium">{{ $user->name }}</div>
                            <div class="text-xs text-slate-500">{{ $user->email }}</div>
                        </td>
                        <td class="hidden text-slate-600 sm:table-cell">{{ $user->phone ?? '—' }}</td>
                        <td>
                            <div>{{ $user->role->label() }}</div>
                            <div class="text-xs text-slate-500">{{ $user->branch?->name ?? ($user->isAdmin() ? 'All outlets' : 'Unassigned') }}</div>
                        </td>
                        <td>
                            <span @class(['badge', 'bg-emerald-100 text-emerald-700' => $user->is_active, 'bg-slate-100 text-slate-500' => ! $user->is_active])>
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            @unless ($user->is(auth()->user()))
                                <form method="POST" action="{{ route('users.toggle', $user) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <button class="mr-3 text-slate-600 hover:underline">{{ $user->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                            @endunless
                            <a href="{{ route('users.edit', $user) }}" class="font-medium text-orange-700 hover:underline">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center text-slate-500">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $users->links() }}</div>
</x-layouts.app>
