<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\SuperAdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class UserController extends Controller
{
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'role' => (string) $request->query('role', ''),
        ];

        $users = User::query()
            ->with('roles')
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['role'] !== '', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('slug', $filters['role'])))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', [
            'title' => 'Users',
            'breadcrumbs' => [['label' => 'System'], ['label' => 'Users']],
            'users' => $users,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'roles' => Role::query()->orderBy('name')->pluck('name', 'slug')->all(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'title' => 'Create User',
            'breadcrumbs' => [['label' => 'Users', 'route' => 'admin.users.index'], ['label' => 'Create']],
            'user' => new User(['status' => 'active']),
            'roles' => Role::query()->orderBy('name')->get(),
            'assignedRoleIds' => [],
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'roles' => ['array'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'],
            // Hashed by the model's 'password' => 'hashed' cast.
            'password' => $data['password'],
            'email_verified_at' => now(),
        ]);

        $user->roles()->sync($data['roles'] ?? []);

        return redirect()->route('admin.users.show', $user)
            ->with('success', "“{$user->name}” তৈরি হয়েছে।");
    }

    public function show(User $user): View
    {
        $user->load('roles.permissions');

        return view('admin.users.show', [
            'title' => $user->name,
            'breadcrumbs' => [['label' => 'Users', 'route' => 'admin.users.index'], ['label' => $user->name]],
            'user' => $user,
            'isLastSuperAdmin' => SuperAdminGuard::isLastUsable($user),
        ]);
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', [
            'title' => 'Edit — '.$user->name,
            'breadcrumbs' => [
                ['label' => 'Users', 'route' => 'admin.users.index'],
                ['label' => $user->name, 'route' => 'admin.users.show', 'params' => $user],
                ['label' => 'Edit'],
            ],
            'user' => $user,
            'roles' => Role::query()->orderBy('name')->get(),
            'assignedRoleIds' => $user->roles->pluck('id')->all(),
            'statuses' => self::STATUSES,
            'isLastSuperAdmin' => SuperAdminGuard::isLastUsable($user),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'roles' => ['array'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        $roles = $data['roles'] ?? [];

        if (SuperAdminGuard::wouldOrphanBySync($user, $roles)) {
            return back()->withInput()
                ->with('error', 'এটিই শেষ সক্রিয় Super Admin — এর Super Admin ভূমিকা সরানো যাবে না।');
        }

        if ($data['status'] !== 'active' && SuperAdminGuard::isLastUsable($user)) {
            return back()->withInput()
                ->with('error', 'এটিই শেষ সক্রিয় Super Admin — একে নিষ্ক্রিয় করা যাবে না।');
        }

        // Password is never edited here; use the reset-link action instead.
        $user->update($data);
        $user->roles()->sync($roles);

        return redirect()->route('admin.users.show', $user)
            ->with('success', "“{$user->name}” হালনাগাদ হয়েছে।");
    }

    public function toggleStatus(User $user): RedirectResponse
    {
        $next = $user->status === 'active' ? 'inactive' : 'active';

        if ($next === 'inactive' && SuperAdminGuard::isLastUsable($user)) {
            return back()->with('error', 'এটিই শেষ সক্রিয় Super Admin — একে নিষ্ক্রিয় করা যাবে না।');
        }

        $user->update(['status' => $next]);

        return back()->with('success', "“{$user->name}” এখন ".self::STATUSES[$next].'।');
    }

    /**
     * Sends Laravel's standard reset link. Admins never see or set another
     * user's password directly.
     */
    public function sendPasswordReset(User $user): RedirectResponse
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        return back()->with(
            $status === Password::RESET_LINK_SENT ? 'success' : 'error',
            $status === Password::RESET_LINK_SENT
                ? "“{$user->email}” ঠিকানায় পাসওয়ার্ড রিসেট লিঙ্ক পাঠানো হয়েছে।"
                : 'রিসেট লিঙ্ক পাঠানো যায়নি।',
        );
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'নিজের অ্যাকাউন্ট এখান থেকে মুছে ফেলা যাবে না।');
        }

        if (SuperAdminGuard::isLastUsable($user)) {
            return back()->with('error', 'এটিই শেষ সক্রিয় Super Admin — একে মুছে ফেলা যাবে না।');
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('admin.users.index')->with('success', "“{$name}” মুছে ফেলা হয়েছে।");
    }
}
