<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AboutPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AboutController extends Controller
{
    public function edit(): View
    {
        return view('admin.content.about', [
            'title' => 'আমাদের সম্পর্কে',
            'breadcrumbs' => [['label' => 'বিষয়বস্তু'], ['label' => 'আমাদের সম্পর্কে']],
            'about' => AboutPage::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'introduction' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'history' => ['nullable', 'string', 'max:10000'],
            'why_exists' => ['nullable', 'string', 'max:5000'],
            'identity_explanation' => ['nullable', 'string', 'max:5000'],
            'registration_status' => ['nullable', 'string', 'max:255'],
        ]);
        $data['is_published'] = $request->boolean('is_published');
        $data['updated_by'] = $request->user()->id;

        AboutPage::current()->update($data);

        return redirect()->route('admin.content.about.edit')->with('success', 'About পাতা হালনাগাদ হয়েছে।');
    }
}
