<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisionController extends Controller
{
    private const KEY = 'about.vision';

    public function edit(): View
    {
        return view('admin.content.vision', [
            'title' => __('admin.nav.vision'),
            'breadcrumbs' => [['label' => __('admin.nav.groups.content')], ['label' => __('admin.nav.vision')]],
            'block' => ContentBlock::query()->firstOrCreate(['key' => self::KEY], ['title' => 'দৃষ্টিভঙ্গি', 'group_name' => 'about']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'body_en' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['is_public'] = $request->boolean('is_public');
        $data['updated_by'] = $request->user()->id;

        ContentBlock::query()->where('key', self::KEY)->update($data);

        return redirect()->route('admin.content.vision.edit')->with('success', __('admin.flash.vision_updated'));
    }
}
