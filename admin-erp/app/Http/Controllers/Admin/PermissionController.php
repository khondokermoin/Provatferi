<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\View\View;

/**
 * Permissions are system-managed and intentionally read-only here: the
 * application authorises against fixed slugs, so allowing arbitrary
 * creation/renaming would silently break access control. New keys arrive
 * through Permission::MODULES/ACTIONS and the seeder.
 */
class PermissionController extends Controller
{
    public function index(): View
    {
        $grouped = Permission::query()
            ->withCount('roles')
            ->orderBy('module')->orderBy('action')
            ->get()
            ->groupBy('module');

        return view('admin.permissions.index', [
            'title' => 'Permissions',
            'breadcrumbs' => [['label' => 'System'], ['label' => 'Permissions']],
            'grouped' => $grouped,
        ]);
    }
}
