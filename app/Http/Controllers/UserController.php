<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with('branch:id,name')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->whereLike('name', '%'.$request->q.'%')
                ->orWhereLike('email', '%'.$request->q.'%')
                ->orWhereLike('phone', '%'.$request->q.'%')))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->role))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', (int) $request->branch_id))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('users.index', ['users' => $users, 'branches' => $this->branches()]);
    }

    public function create()
    {
        return view('users.form', [
            'user' => new User(['role' => Role::FrontDesk, 'is_active' => true]),
            'branches' => $this->branches(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data += $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        User::create($data);

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function edit(User $user)
    {
        return view('users.form', ['user' => $user, 'branches' => $this->branches()]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        // Don't let an admin lock themselves out.
        if ($user->is($request->user())) {
            $data['role'] = Role::Admin->value;
            $data['branch_id'] = null;
            $data['is_active'] = true;
        }

        $user->update($data);

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        $user->delete();

        return redirect()->route('users.index')->with('status', 'User deleted.');
    }

    /** Admin sets a new password for a user (no self-service reset). */
    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        $user->forceFill(['password' => $data['password'], 'remember_token' => null])->save();

        return back()->with('status', "Password reset for {$user->name}.");
    }

    public function toggle(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'You cannot deactivate your own account.']);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('status', $user->name.($user->is_active ? ' activated.' : ' deactivated.'));
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 \-]{10,15}$/'],
            'role' => ['required', Rule::enum(Role::class)],
            'branch_id' => ['nullable', 'required_unless:role,'.Role::Admin->value, 'exists:branches,id'],
        ], ['branch_id.required_unless' => 'Outlet managers and front desk users must belong to an outlet.']);

        if ($data['role'] === Role::Admin->value) {
            $data['branch_id'] = null;
        }

        return $data + ['is_active' => $request->boolean('is_active')];
    }

    private function branches()
    {
        return Branch::orderBy('name')->get(['id', 'name', 'city', 'is_active']);
    }
}
