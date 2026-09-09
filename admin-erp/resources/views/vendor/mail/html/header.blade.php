@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{--
    Real logo, not Laravel's default — served from this app's own public/
    disk (see AdminUserSeeder/COLOR_AND_LOGO_GUIDELINES.md §4 for the asset
    rules: light-mode wordmark on a light card, official ratio 1876:859).
    width/height are set as HTML attributes, not just CSS, because Outlook's
    Word rendering engine ignores img sizing that only exists in a <style>
    block.
--}}
<img src="{{ asset('brand/provatferi-logo-light.png') }}"
     class="logo"
     width="176"
     height="81"
     alt="প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র">
</a>
</td>
</tr>
