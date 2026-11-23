<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index()
    {
        $branches = Branch::with('staff:id,name,role,branch_id,is_active')
            ->orderBy('name')
            ->paginate(25);

        return view('branches.index', compact('branches'));
    }

    public function create()
    {
        return view('branches.form', ['branch' => new Branch(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        $branch = Branch::create($this->validated($request));

        return redirect()->route('branches.edit', $branch)->with('status', 'Outlet created.');
    }

    public function edit(Branch $branch)
    {
        $branch->load('staff');
        $availableManagers = User::whereIn('role', Role::outletRoleValues())
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', '!=', $branch->id))
            ->with('branch:id,name')
            ->orderBy('name')
            ->get();

        return view('branches.form', compact('branch', 'availableManagers'));
    }

    public function update(Request $request, Branch $branch)
    {
        $branch->update($this->validated($request, $branch));

        return redirect()->route('branches.index')->with('status', 'Outlet updated.');
    }

    public function destroy(Branch $branch)
    {
        if ($branch->users()->exists() || $branch->leads()->exists() || $branch->bookings()->exists()) {
            return back()->withErrors(['branch' => 'This outlet still has staff, enquiries or bookings. Deactivate it instead.']);
        }

        $branch->delete();

        return redirect()->route('branches.index')->with('status', 'Outlet deleted.');
    }

    /** Assign (or move) an outlet manager / front desk user to this outlet. */
    public function assignManager(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->whereIn('role', Role::outletRoleValues())],
        ]);

        User::whereKey($data['user_id'])->update(['branch_id' => $branch->id]);

        return back()->with('status', 'Staff member assigned.');
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'alpha_dash:ascii', 'max:60', Rule::unique('branches', 'slug')->ignore($branch)],
            'city' => ['required', 'string', 'max:100'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
