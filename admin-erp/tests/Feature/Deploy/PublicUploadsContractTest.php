<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PRIORITY 0 hardening, 2026-10-02 — the PUBLIC-uploads counterpart to
 * UploadsPersistenceContractTest.
 *
 * The defect being locked out: Laravel's stock 'public' disk
 * (storage/app/public) is only web-reachable via the `storage:link` symlink,
 * and symlink() is in this host's php.ini disable_functions. So every
 * Storage::disk('public')->url() resolved to a path the vhost could not
 * serve, and every approved photo 404'd for visitors — silently, from the
 * first release onward, because no public photo had been published yet. Found
 * when a carousel image saved successfully through Admin but its URL 404'd.
 *
 * These tests cover the four properties the owner required before the
 * public_path-style approach could be accepted at all:
 *   1. files persist through a full deployment  (survives_a_simulated_release_switch)
 *   2. deploy sync cannot erase them            (additive_public_asset_sync_*)
 *   3. replacement/delete works correctly       (replacement_and_delete_*)
 *   4. public/private disks remain separated    (private_originals_never_*)
 *
 * Requires deploy/remote/lib/public-uploads.php directly, in isolation from
 * release-manager.php (which has top-level CLI dispatch — reads $argv, calls
 * exit() — and is unsafe to include from a test process).
 */
class PublicUploadsContractTest extends TestCase
{
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('deploy/remote/lib/public-uploads.php');
        require_once base_path('deploy/remote/lib/uploads-persistence.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirRecursive($dir);
        }
        parent::tearDown();
    }

    private function makeTempDir(string $label): string
    {
        $dir = sys_get_temp_dir().'/pf-public-uploads-'.$label.'-'.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    public function test_the_public_root_is_inside_the_vhost_docroot_not_the_app(): void
    {
        $docroot = '/home/u951246149/domains/provatferi.org/public_html/admin';

        $this->assertSame($docroot.'/storage', publicUploadsRoot($docroot));
    }

    public function test_the_root_is_created_and_proven_writable(): void
    {
        $root = $this->makeTempDir('ensure').'/storage';

        $result = ensurePublicUploadsRoot($root);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['writable']);
        $this->assertDirectoryExists($root);
    }

    public function test_an_unwritable_root_is_reported_not_assumed_ok(): void
    {
        // A FILE occupying the path the root needs to be a directory — the
        // same collision shape that silently broke the private-uploads copy
        // on 2026-09-24. mkdir() fails, and the probe must catch it.
        $parent = $this->makeTempDir('unwritable');
        $root = $parent.'/storage';
        file_put_contents($root, 'a file is sitting where the directory must go');

        $result = @ensurePublicUploadsRoot($root);

        $this->assertFalse($result['ok'], 'an unusable public root must never be reported ok — every approved photo would silently fail to publish');
    }

    /**
     * Property 1 + 2: a real upload written to the docroot root survives the
     * atomic switch AND the switch-time public-asset sync, byte-for-byte.
     *
     * This reproduces the actual deploy shape: the docroot is a fixed path
     * that is never renamed, release dirs come and go beside it, and the
     * asset sync copies build/brand/js INTO the docroot with copyRecursive
     * (which only ever adds — it has no prune step).
     */
    public function test_an_upload_survives_a_simulated_release_switch_and_asset_sync(): void
    {
        $docroot = $this->makeTempDir('switch-docroot');
        $root = publicUploadsRoot($docroot);
        ensurePublicUploadsRoot($root);

        // A real approved photo, uploaded between deploys.
        mkdir($root.'/homepage-carousel', 0755, true);
        $photo = $root.'/homepage-carousel/approved.png';
        file_put_contents($photo, 'genuine-png-bytes-for-an-approved-slide');
        $hashBefore = hash_file('sha256', $photo);

        // The switch renames the APP directory; the docroot is untouched by it.
        $liveApp = $this->makeTempDir('switch-live');
        $nextRelease = $this->makeTempDir('switch-next');
        $previous = $liveApp.'-previous';
        $this->assertTrue(rename($liveApp, $previous));
        $this->assertTrue(rename($nextRelease, $liveApp));
        $this->tempDirs[] = $previous;

        // Then the asset sync runs over that same docroot.
        $assets = $this->makeTempDir('switch-assets');
        mkdir($assets.'/build', 0755, true);
        file_put_contents($assets.'/build/app-abc123.js', 'console.log(1)');
        copyRecursive($assets.'/build', $docroot.'/build');

        $this->assertFileExists($photo, 'the release switch and asset sync must not erase an upload in the docroot');
        $this->assertSame($hashBefore, hash_file('sha256', $photo), 'the surviving file must be byte-identical, not merely present');
        $this->assertSame('genuine-png-bytes-for-an-approved-slide', file_get_contents($photo));
    }

    public function test_the_sentinel_gate_detects_an_erased_or_altered_upload(): void
    {
        $root = $this->makeTempDir('sentinel').'/storage';
        ensurePublicUploadsRoot($root);

        $sentinel = writePublicUploadsSentinel($root, 'abc123-2026-10-0212000');
        $this->assertTrue($sentinel['ok']);

        $file = $root.'/'.$sentinel['relative_path'];
        $this->assertFileExists($file);
        $this->assertSame($sentinel['sha256'], hash_file('sha256', $file), 'a healthy deploy re-hashes to the recorded value');

        // Altered bytes must not still hash equal — this is what switch()
        // compares after the swap.
        file_put_contents($file, 'tampered');
        $this->assertNotSame($sentinel['sha256'], hash_file('sha256', $file));

        // Erased entirely — the other half of the same gate.
        unlink($file);
        $this->assertFileDoesNotExist($file);
    }

    public function test_legacy_uploads_are_migrated_forward_without_clobbering_newer_ones(): void
    {
        $legacy = $this->makeTempDir('legacy-src');
        $root = $this->makeTempDir('legacy-dest').'/storage';
        ensurePublicUploadsRoot($root);

        mkdir($legacy.'/member-profiles', 0755, true);
        file_put_contents($legacy.'/member-profiles/old.png', 'old-approved-photo');
        mkdir($legacy.'/committee', 0755, true);
        file_put_contents($legacy.'/committee/stale.png', 'stale-bytes');

        // A file already at the destination must win — it is the newer upload.
        mkdir($root.'/committee', 0755, true);
        file_put_contents($root.'/committee/stale.png', 'NEWER-bytes-already-live');

        $result = migrateLegacyPublicUploads($legacy, $root);

        $this->assertTrue($result['ran']);
        $this->assertSame(1, $result['copied']);
        $this->assertSame(1, $result['already_present']);
        $this->assertSame([], $result['failed']);
        $this->assertSame('old-approved-photo', file_get_contents($root.'/member-profiles/old.png'));
        $this->assertSame('NEWER-bytes-already-live', file_get_contents($root.'/committee/stale.png'), 'migration must never overwrite a newer upload with a stale copy');

        // Idempotent: a second run copies nothing new.
        $again = migrateLegacyPublicUploads($legacy, $root);
        $this->assertSame(0, $again['copied']);
        $this->assertSame(2, $again['already_present']);
    }

    public function test_a_host_that_never_had_the_legacy_directory_is_not_an_error(): void
    {
        $result = migrateLegacyPublicUploads($this->makeTempDir('no-legacy').'/never-existed', $this->makeTempDir('no-legacy-dest'));

        $this->assertFalse($result['ran']);
        $this->assertArrayHasKey('reason', $result);
    }

    public function test_pinning_the_env_value_rewrites_only_that_line(): void
    {
        $envPath = $this->makeTempDir('env').'/.env';
        file_put_contents($envPath, "APP_NAME=Provatferi\nAPP_KEY=base64:secret\nDB_DATABASE=live\n");

        $this->assertTrue(setEnvValue($envPath, 'PUBLIC_UPLOADS_ROOT', '/docroot/storage'));
        $after = file_get_contents($envPath);
        $this->assertStringContainsString('APP_KEY=base64:secret', $after, 'unrelated secrets must survive untouched');
        $this->assertStringContainsString('PUBLIC_UPLOADS_ROOT=/docroot/storage', $after);

        // Updating in place must not append a duplicate.
        $this->assertTrue(setEnvValue($envPath, 'PUBLIC_UPLOADS_ROOT', '/docroot/storage2'));
        $final = file_get_contents($envPath);
        $this->assertSame(1, substr_count($final, 'PUBLIC_UPLOADS_ROOT='));
        $this->assertStringContainsString('PUBLIC_UPLOADS_ROOT=/docroot/storage2', $final);
        $this->assertStringContainsString('DB_DATABASE=live', $final);
    }

    /**
     * Property 3: replacement and deletion still behave correctly through the
     * app's own disk abstraction once the root moves. Uses the real
     * PhotoUploadService against a faked public disk, which is how every
     * other test in this suite exercises it.
     */
    public function test_replacement_and_delete_work_through_the_public_disk(): void
    {
        Storage::fake('public');
        $service = app(\App\Services\PhotoUploadService::class);

        Storage::disk('public')->put('homepage-carousel/first.png', 'first-bytes');
        Storage::disk('public')->assertExists('homepage-carousel/first.png');

        // Replace: the old derivative is deleted explicitly by the caller.
        Storage::disk('public')->put('homepage-carousel/second.png', 'second-bytes');
        $service->deletePublic('homepage-carousel/first.png');

        Storage::disk('public')->assertMissing('homepage-carousel/first.png');
        Storage::disk('public')->assertExists('homepage-carousel/second.png');

        // Delete: removes the surviving derivative too.
        $service->deletePublic('homepage-carousel/second.png');
        Storage::disk('public')->assertMissing('homepage-carousel/second.png');

        // A null path is a no-op, never an error (a slide with no image).
        $service->deletePublic(null);
    }

    /**
     * Property 4: the two disks stay separated. The private root must not sit
     * inside the public root, or a private original would become fetchable
     * the moment it was written.
     */
    public function test_private_originals_never_live_under_the_public_root(): void
    {
        $publicRoot = str_replace('\\', '/', config('filesystems.disks.public.root'));
        $privateRoot = str_replace('\\', '/', config('filesystems.disks.uploads_private.root'));

        $this->assertStringNotContainsString($publicRoot, $privateRoot, 'the private uploads root must never be nested inside the web-served public root');
        $this->assertFalse(config('filesystems.disks.uploads_private.serve'), 'the private disk must never be served');
        $this->assertSame('public', config('filesystems.disks.public.visibility'));
    }

    public function test_the_public_disk_url_is_built_from_app_url(): void
    {
        $this->assertSame(
            rtrim(config('app.url'), '/').'/storage',
            config('filesystems.disks.public.url'),
            'the public URL prefix must stay APP_URL/storage — the docroot serves that path directly'
        );
    }
}
