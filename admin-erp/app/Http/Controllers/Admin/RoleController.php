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
            'title' => __('admin.nav.roles'),
            'breadcrumbs' => [['label' => __('admin.nav.groups.system')], ['label' => __('admin.nav.roles')]],
            'roles' => Role::query()->withCount(['users', 'permissions'])->orderBy('name')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.form', [
            'title' => __('admin.fields.new_role'),
            'breadcrumbs' => [['label' => __('admin.nav.roles'), 'route' => 'admin.roles.index'], ['label' => __('admin.actions.create')]],
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

        return redirect()->route('admin.roles.index')->with('success', __('admin.flash.role_created', ['name' => $role->name]));
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', [
            'title' => __('admin.fields.edit_prefix').' — '.$role->name,
            'breadcrumbs' => [['label' => __('admin.nav.roles'), 'route' => 'admin.roles.index'], ['label' => $role->name]],
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
                ->with('warning', __('admin.flash.super_admin_permissions_not_applied'));
        }

        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', __('admin.flash.role_updated', ['name' => $role->name]));
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system_role) {
            return back()->with('error', __('admin.fields.system_role_cannot_delete', ['name' => $role->name]));
        }

        if ($role->users()->exists()) {
            return back()->with('error', __('admin.fields.role_in_use_cannot_delete', ['count' => $role->users()->count()]));
        }

        $name = $role->name;
        $role->permissions()->detach();
        $role->delete();

        return redirect()->route('admin.roles.index')->with('success', __('admin.flash.role_deleted', ['name' => $name]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', Rule::exists('permissions', 'id')],
        ], [], ['name' => __('admin.fields.role_name')]);
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
