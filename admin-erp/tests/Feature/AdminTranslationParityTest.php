<?php

namespace Tests\Feature;

use App\Support\AdminLocale;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Phase 3 — the bilingual admin's safety net.
 *
 * PHP arrays give no compile-time guarantee that lang/bn and lang/en carry
 * the same keys, so a key added to one language and forgotten in the other
 * would silently render the raw key path ("admin.nav.foo") to a real admin.
 * This is the only thing that catches that.
 */
class AdminTranslationParityTest extends TestCase
{
    /** @return array<int, string> dot-notation leaf keys */
    private function leafKeys(array $translations): array
    {
        $keys = array_keys(Arr::dot($translations));
        sort($keys);

        return $keys;
    }

    /** @return array<int, string> */
    private function load(string $locale, string $file): array
    {
        $path = lang_path("{$locale}/{$file}.php");
        $this->assertFileExists($path, "Missing translation file: lang/{$locale}/{$file}.php");

        return require $path;
    }

    public static function translationFiles(): array
    {
        return [
            'admin' => ['admin'],
            'statuses' => ['statuses'],
        ];
    }

    /**
     * @dataProvider translationFiles
     */
    public function test_bn_and_en_declare_exactly_the_same_keys(string $file): void
    {
        $bn = $this->leafKeys($this->load('bn', $file));
        $en = $this->leafKeys($this->load('en', $file));

        $missingInEn = array_values(array_diff($bn, $en));
        $missingInBn = array_values(array_diff($en, $bn));

        $this->assertSame([], $missingInEn, "Keys present in lang/bn/{$file}.php but missing from lang/en/{$file}.php: ".implode(', ', $missingInEn));
        $this->assertSame([], $missingInBn, "Keys present in lang/en/{$file}.php but missing from lang/bn/{$file}.php: ".implode(', ', $missingInBn));
    }

    /**
     * A key whose value is identical in both files is usually a forgotten
     * translation. A small set is legitimately identical — proper nouns, the
     * switcher's own labels (each always written in its own language), an
     * em-dash placeholder — so those are allowlisted by key rather than the
     * check being dropped.
     *
     * @dataProvider translationFiles
     */
    public function test_no_english_value_was_left_as_its_bangla_original(string $file): void
    {
        $allowed = [
            'brand.org', 'brand.org_full', 'brand.panel', 'brand.footer', 'brand.org_short',
            'bilingual.bn_tab', 'bilingual.en_tab',
            'common.not_set', 'nav.planned',
            'a11y.dashboard_home',
        ];

        $bn = Arr::dot($this->load('bn', $file));
        $en = Arr::dot($this->load('en', $file));

        $untranslated = [];
        foreach ($bn as $key => $value) {
            if (in_array($key, $allowed, true) || ! is_string($value)) {
                continue;
            }
            // Only flag values that actually contain Bengali script — an
            // English value that legitimately matches (e.g. "WhatsApp") is fine.
            if (($en[$key] ?? null) === $value && preg_match('/[\x{0980}-\x{09FF}]/u', $value)) {
                $untranslated[] = $key;
            }
        }

        $this->assertSame([], $untranslated, "These lang/en/{$file}.php values are still the Bangla original: ".implode(', ', $untranslated));
    }

    public function test_every_supported_locale_has_a_full_admin_catalogue(): void
    {
        foreach (AdminLocale::codes() as $locale) {
            $this->assertFileExists(lang_path("{$locale}/admin.php"), "AdminLocale advertises '{$locale}' but lang/{$locale}/admin.php does not exist.");
            $this->assertFileExists(lang_path("{$locale}/statuses.php"), "AdminLocale advertises '{$locale}' but lang/{$locale}/statuses.php does not exist.");
        }
    }
}
