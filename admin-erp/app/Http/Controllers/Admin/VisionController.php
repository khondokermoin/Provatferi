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
            'title' => 'Vision',
            'breadcrumbs' => [['label' => 'Content'], ['label' => 'Vision']],
            'block' => ContentBlock::query()->firstOrCreate(['key' => self::KEY], ['title' => 'Vision', 'group_name' => 'about']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $data['is_public'] = $request->boolean('is_public');
        $data['updated_by'] = $request->user()->id;

        ContentBlock::query()->where('key', self::KEY)->update($data);

        return redirect()->route('admin.content.vision.edit')->with('success', 'Vision হালনাগাদ হয়েছে।');
    }
}
