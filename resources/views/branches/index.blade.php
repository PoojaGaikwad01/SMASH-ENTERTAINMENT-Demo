<x-layouts.app title="Outlets">
    <x-slot:actions>
        <a href="{{ route('branches.create') }}" class="btn btn-primary">+ New outlet</a>
    </x-slot:actions>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr><th>Outlet</th><th>Staff</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($branches as $branch)
                    <tr>
                        <td>
                            <div class="font-medium">{{ $branch->name }}</div>
                            <div class="text-xs text-slate-500">{{ $branch->city }} · /enquiry/{{ $branch->slug }}</div>
                        </td>
                        <td class="text-slate-600">
                            {{ $branch->staff->pluck('name')->join(', ') ?: '—' }}
                        </td>
                        <td>
                            <span @class(['badge', 'bg-emerald-100 text-emerald-700' => $branch->is_active, 'bg-slate-100 text-slate-500' => ! $branch->is_active])>
                                {{ $branch->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="text-right">
                            <a href="{{ route('branches.qr', $branch) }}" target="_blank" class="mr-3 text-slate-600 hover:underline">QR poster</a>
                            <a href="{{ route('branches.edit', $branch) }}" class="font-medium text-orange-700 hover:underline">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-8 text-center text-slate-500">No outlets yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $branches->links() }}</div>
</x-layouts.app>
