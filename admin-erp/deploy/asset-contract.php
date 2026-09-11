<?php
/**
 * Deployment-contract check: proves every Provatferi-owned public asset the
 * app actually needs is present in the build BEFORE it gets packaged, and
 * fails the build otherwise.
 *
 * This exists because of a real incident: deploy/build-release.sh already
 * packaged public/build (Vite output) and public/brand (logo PNGs), but
 * public/zircos/css/provatferi-admin.css lived outside both and was never
 * part of any artifact — a change to it required a manual file sync
 * ("SYSTEM-006" in ADMIN_AUTH_PROFILE_UI_AUDIT.md), which is exactly the
 * ad-hoc practice this whole pipeline was built to replace. Moving that
 * file into the Vite pipeline (resources/css/provatferi-admin.css) fixes
 * the omission structurally; this script is the check that makes a future
 * regression of the same kind fail the build instead of shipping silently.
 *
 * Usage: php deploy/asset-contract.php <app-path> [report-json-path]
 * Exit 0 + {"pass":true,...} on success. Exit 1 + {"pass":false,...} and a
 * "missing" list on failure.
 */

$appPath = $argv[1] ?? null;
$reportPath = $argv[2] ?? null;
if (!$appPath || !is_dir($appPath)) {
    fwrite(STDERR, "Usage: php asset-contract.php <app-path> [report-json-path]\n");
    exit(2);
}

$missing = [];
$present = [];

function checkFile(string $path, string $label, array &$present, array &$missing): void
{
    if (is_file($path)) {
        $present[] = ['label' => $label, 'path' => $path, 'bytes' => filesize($path)];
    } else {
        $missing[] = ['label' => $label, 'path' => $path];
    }
}

// 1. Vite manifest must exist and be valid JSON.
$manifestPath = $appPath.'/public/build/manifest.json';
$manifest = null;
if (is_file($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
}
if (!is_array($manifest)) {
    $missing[] = ['label' => 'public/build/manifest.json', 'path' => $manifestPath, 'reason' => 'missing or not valid JSON'];
} else {
    $present[] = ['label' => 'public/build/manifest.json', 'path' => $manifestPath];

    // 2. The brand/override stylesheet must be a real Vite entry, and its
    //    hashed output file must physically exist — this is the exact
    //    check that would have caught the SYSTEM-006 omission.
    $cssEntry = $manifest['resources/css/provatferi-admin.css'] ?? null;
    if (!$cssEntry || empty($cssEntry['isEntry']) || empty($cssEntry['file'])) {
        $missing[] = ['label' => 'provatferi-admin.css Vite entry', 'path' => 'manifest["resources/css/provatferi-admin.css"]', 'reason' => 'not present or not marked isEntry'];
    } else {
        checkFile($appPath.'/public/build/'.$cssEntry['file'], 'built provatferi-admin.css', $present, $missing);
    }

    // 3. The two floral-texture assets the CSS references via url() must
    //    also have survived the build (Vite resolves/copies/hashes them
    //    automatically — this proves that actually happened).
    foreach (['resources/images/textures/provatferi-floral-light-sm.webp', 'resources/images/textures/provatferi-floral-dark-sm.webp'] as $key) {
        $entry = $manifest[$key] ?? null;
        if (!$entry || empty($entry['file'])) {
            $missing[] = ['label' => $key, 'path' => "manifest[\"$key\"]", 'reason' => 'not present in manifest'];
        } else {
            checkFile($appPath.'/public/build/'.$entry['file'], $key, $present, $missing);
        }
    }
}

// 4. Official brand logo assets — not Vite-built, but must exist as plain
//    static files (build-release.sh packages public/brand/ verbatim).
foreach ([
    'provatferi-logo-light.png',
    'provatferi-logo-dark.png',
    'provatferi-icon-light.png',
    'provatferi-icon-dark.png',
] as $file) {
    checkFile($appPath.'/public/brand/'.$file, "brand asset: $file", $present, $missing);
}

// 5. Favicon.
checkFile($appPath.'/public/favicon.ico', 'favicon.ico', $present, $missing);

// 6. Explicitly confirm the artifact does NOT carry what it must never
//    carry — this is a "prove what's excluded" check, not just "what's
//    included". A hit here fails the build the same as a missing asset.
$forbidden = [];
foreach (['.env', '.env.testing', '.env.local'] as $envFile) {
    if (is_file($appPath.'/'.$envFile)) {
        $forbidden[] = $appPath.'/'.$envFile;
    }
}
if (is_dir($appPath.'/storage/logs')) {
    $logFiles = glob($appPath.'/storage/logs/*.log') ?: [];
    if (!empty($logFiles)) {
        $forbidden[] = $appPath.'/storage/logs/*.log ('.count($logFiles).' file(s))';
    }
}

$result = [
    'pass' => empty($missing) && empty($forbidden),
    'checked_at' => date('c'),
    'present' => $present,
    'missing' => $missing,
    'forbidden_present' => $forbidden,
];

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($reportPath) {
    file_put_contents($reportPath, $json);
}
echo $json."\n";

exit($result['pass'] ? 0 : 1);
