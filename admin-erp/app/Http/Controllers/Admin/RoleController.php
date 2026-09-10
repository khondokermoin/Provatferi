<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\SuperAdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('admin.roles.index', [
            'title' => 'ভূমিকা',
            'breadcrumbs' => [['label' => 'সিস্টেম'], ['label' => 'ভূমিকা']],
            'roles' => Role::query()->withCount(['users', 'permissions'])->orderBy('name')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.form', [
            'title' => 'নতুন ভূমিকা',
            'breadcrumbs' => [['label' => 'ভূমিকা', 'route' => 'admin.roles.index'], ['label' => 'তৈরি করুন']],
            'role' => new Role(),
            'grouped' => $this->groupedPermissions(),
            'assigned' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = Role::query()->create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'description' => $data['description'] ?? null,
            'is_system_role' => false,
        ]);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', "“{$role->name}” তৈরি হয়েছে।");
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', [
            'title' => 'সম্পাদনা — '.$role->name,
            'breadcrumbs' => [['label' => 'ভূমিকা', 'route' => 'admin.roles.index'], ['label' => $role->name]],
            'role' => $role,
            'grouped' => $this->groupedPermissions(),
            'assigned' => $role->permissions->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);

        // Super Admin's authority is structural: it must always hold everything.
        if ($role->slug === SuperAdminGuard::ROLE) {
            $role->update(['description' => $data['description'] ?? $role->description]);
            $role->permissions()->sync(Permission::query()->pluck('id'));

            return redirect()->route('admin.roles.index')
                ->with('warning', 'Super Admin সবসময় সব permission ধরে রাখে — permission পরিবর্তন প্রয়োগ করা হয়নি।');
        }

        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', "“{$role->name}” হালনাগাদ হয়েছে।");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system_role) {
            return back()->with('error', "“{$role->name}” একটি সিস্টেম ভূমিকা — মুছে ফেলা যাবে না।");
        }

        if ($role->users()->exists()) {
            return back()->with('error', "“{$role->name}” এখনো {$role->users()->count()} জন ব্যবহারকারীর সঙ্গে যুক্ত — আগে তাদের সরান।");
        }

        $name = $role->name;
        $role->permissions()->detach();
        $role->delete();

        return redirect()->route('admin.roles.index')->with('success', "“{$name}” মুছে ফেলা হয়েছে।");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', Rule::exists('permissions', 'id')],
        ], [], ['name' => 'ভূমিকার নাম']);
    }

    /**
     * Permissions grouped by module for the assignment UI. They are seeded and
     * system-managed — this screen assigns them, it never invents new keys,
     * because the app authorises against fixed slugs.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function groupedPermissions(): array
    {
        return Permission::query()
            ->orderBy('module')->orderBy('action')->get()
            ->groupBy('module')
            ->all();
    }
}
