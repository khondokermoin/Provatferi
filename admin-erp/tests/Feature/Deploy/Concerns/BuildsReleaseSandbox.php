<?php

namespace Tests\Feature\Deploy\Concerns;

/**
 * A throwaway copy of the production directory layout in which the REAL
 * deploy/remote/release-manager.php runs its REAL `stage` and `switch` (and
 * `rollback`, `reconcile`) actions as a subprocess — the same file, the same lib/,
 * the same atomic renames, only the application inside is a stub.
 *
 *   <sandbox>/                         the "domain root"
 *     laravel-admin/                   the LIVE application (a stub, with an uploads tree)
 *     laravel-admin-releases/_tooling/ release-manager.php + lib/*.php (copied from deploy/remote)
 *     public_html/admin/               the vhost docroot (public uploads root = storage/)
 *       _release_staging/<releaseId>/  the uploaded artifact (private.tar, public-assets.tar, manifest.json)
 *
 * Why a sandbox rather than unit tests of the libraries alone: the defect this
 * exists to guard against (uploads written between `stage` and `switch` being
 * stranded) is a property of how the orchestrator sequences those two actions, and
 * a library test cannot prove the orchestrator calls it. These tests run the
 * sequence for real.
 *
 * `build`, `contract-check`, `migrate-check` and `smoke-test-isolated` need Composer
 * and a booted Laravel, which a stub app cannot provide, so they are recorded as
 * passed in status.json (exactly what `switch` consults) via markPipelineStagesPassed().
 * Inside `switch` the post-swap cache rebuild boots the stub, fails harmlessly inside the
 * orchestrator's own try/catch, and is reported as cache_rebuild.error — expected here.
 */
trait BuildsReleaseSandbox
{
    protected string $sandbox = '';

    protected string $liveApp = '';

    protected string $releasesRoot = '';

    protected string $docroot = '';

    /** @var string[] */
    private array $sandboxDirs = [];

    protected function makeSandbox(): void
    {
        $this->sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pf-release-sandbox-'.bin2hex(random_bytes(6));
        $this->sandboxDirs[] = $this->sandbox;
        mkdir($this->sandbox, 0755, true);
        $this->sandbox = realpath($this->sandbox);
        $this->liveApp = $this->sandbox.DIRECTORY_SEPARATOR.'laravel-admin';
        $this->releasesRoot = $this->sandbox.DIRECTORY_SEPARATOR.'laravel-admin-releases';
        $this->docroot = $this->sandbox.DIRECTORY_SEPARATOR.'public_html'.DIRECTORY_SEPARATOR.'admin';

        $tooling = $this->releasesRoot.DIRECTORY_SEPARATOR.'_tooling';
        mkdir($tooling.DIRECTORY_SEPARATOR.'lib', 0755, true);
        mkdir($this->docroot.DIRECTORY_SEPARATOR.'_release_staging', 0755, true);

        $remote = base_path('deploy/remote');
        copy($remote.'/release-manager.php', $tooling.'/release-manager.php');
        foreach (glob($remote.'/lib/*.php') as $lib) {
            copy($lib, $tooling.'/lib/'.basename($lib));
        }

        $this->writeStubApp($this->liveApp, 'live-v1');
    }

    protected function destroySandbox(): void
    {
        foreach ($this->sandboxDirs as $dir) {
            $this->sandboxRemove($dir);
        }
        $this->sandboxDirs = [];
    }

    protected function sandboxRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @chmod($item->getPathname(), 0755);
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** The smallest tree release-manager.php's actions need from an application. */
    protected function writeStubApp(string $dir, string $marker): void
    {
        foreach (['routes', 'bootstrap', 'vendor', 'storage/app/private/uploads', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $sub) {
            @mkdir($dir.'/'.$sub, 0755, true);
        }
        file_put_contents($dir.'/composer.json', '{"name":"stub/app","require":{}}');
        file_put_contents($dir.'/artisan', "<?php // stub\n");
        file_put_contents($dir.'/routes/web.php', "<?php // routes of {$marker}\n");
        // Returning a non-Application makes bootApp()'s return type fail inside the orchestrator's try/catch.
        file_put_contents($dir.'/bootstrap/app.php', "<?php return new stdClass;\n");
        file_put_contents($dir.'/vendor/autoload.php', "<?php // stub autoload\n");
        file_put_contents($dir.'/.env', "APP_KEY=base64:stub\nAPP_MARKER={$marker}\n");
        file_put_contents($dir.'/MARKER', $marker);
    }

    /** Builds and "uploads" a release artifact for $releaseId into the staging directory. */
    protected function stageArtifact(string $releaseId, string $marker = 'release-v2'): void
    {
        $work = $this->sandbox.DIRECTORY_SEPARATOR.'_artifact-'.$releaseId;
        $this->sandboxDirs[] = $work;
        $this->writeStubApp($work.'/private/admin-erp', $marker);
        // the real artifact never carries a .env or vendor/ (build-release.sh), stage copies .env from live
        @unlink($work.'/private/admin-erp/.env');
        mkdir($work.'/public/build/assets', 0755, true);
        mkdir($work.'/public/brand', 0755, true);
        mkdir($work.'/public/js', 0755, true);
        file_put_contents($work.'/public/build/manifest.json', '{"stub":true}');
        file_put_contents($work.'/public/build/assets/app-'.$releaseId.'.js', "// asset of {$releaseId}\n");
        file_put_contents($work.'/public/brand/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        file_put_contents($work.'/public/js/stub.js', '// stub');
        file_put_contents($work.'/public/favicon.ico', 'ico');
        file_put_contents($work.'/public/robots.txt', 'User-agent: *');

        $staging = $this->docroot.DIRECTORY_SEPARATOR.'_release_staging'.DIRECTORY_SEPARATOR.$releaseId;
        mkdir($staging, 0755, true);

        $privateTar = $staging.DIRECTORY_SEPARATOR.'private.tar';
        $privateArchive = new \PharData($privateTar);
        $privateArchive->buildFromDirectory($work.'/private'); // top-level entry: admin-erp/, as build-release.sh packs it

        $publicTar = $staging.DIRECTORY_SEPARATOR.'public-assets.tar';
        $publicArchive = new \PharData($publicTar);
        $publicArchive->buildFromDirectory($work.'/public');
        unset($privateArchive, $publicArchive);

        file_put_contents($staging.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
            'commit' => str_pad($releaseId, 40, '0'),
            'artifacts' => [
                'private_tar' => ['file' => 'private.tar', 'sha256' => hash_file('sha256', $privateTar)],
                'public_assets_tar' => ['file' => 'public-assets.tar', 'sha256' => hash_file('sha256', $publicTar)],
            ],
        ]));
    }

    /**
     * Runs release-manager.php from the sandbox's tooling directory.
     *
     * @param  string[]  $args
     * @param  array<string,string>  $env
     * @return array{exit:int, json:?array, stdout:string, stderr:string}
     */
    protected function runTool(array $args, array $env = []): array
    {
        return $this->finishTool($this->startTool($args, $env));
    }

    /**
     * @param  string[]  $args
     * @param  array<string,string>  $env
     * @return array{process:resource, pipes:array, args:string[]}
     */
    protected function startTool(array $args, array $env = []): array
    {
        $cmd = array_merge([PHP_BINARY, $this->releasesRoot.'/_tooling/release-manager.php'], $args);
        $environment = array_merge(array_map('strval', getenv()), ['RM_SWEEP_DELAY' => '0'], $env);
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->sandbox, $environment);
        $this->assertIsResource($process, 'could not start release-manager.php');
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes, 'args' => $args];
    }

    /** @return array{exit:int, json:?array, stdout:string, stderr:string} */
    protected function finishTool(array $handle): array
    {
        $stdout = stream_get_contents($handle['pipes'][1]);
        $stderr = stream_get_contents($handle['pipes'][2]);
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        $exit = proc_close($handle['process']);

        // PHP prints startup warnings on stdout on some hosts; the report starts at the first brace.
        $start = strpos($stdout, '{');
        $json = $start === false ? null : json_decode(substr($stdout, $start), true);

        return ['exit' => $exit, 'json' => is_array($json) ? $json : null, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    protected function markPipelineStagesPassed(string $releaseId): void
    {
        $path = $this->releasesRoot.'/'.$releaseId.'/status.json';
        $status = json_decode((string) file_get_contents($path), true) ?: [];
        foreach (['build', 'contract_check', 'migrate_check', 'smoke_test_isolated'] as $key) {
            $status[$key] = ['ok' => true, 'recorded_by' => 'test harness (needs composer / a booted app)'];
        }
        file_put_contents($path, json_encode($status));
    }

    /** Stages a release and records the heavy pipeline steps as passed. Returns stage's report. */
    protected function stageRelease(string $releaseId, string $marker = 'release-v2'): array
    {
        $this->stageArtifact($releaseId, $marker);
        $stage = $this->runTool(['stage', $releaseId]);
        $this->assertSame(0, $stage['exit'], 'stage must succeed: '.$stage['stdout'].$stage['stderr']);
        $this->markPipelineStagesPassed($releaseId);

        return $stage['json'];
    }

    protected function liveUploadsDir(): string
    {
        return $this->liveApp.'/storage/app/private/uploads';
    }

    protected function publicUploadsDir(): string
    {
        return $this->docroot.'/storage';
    }

    /** Writes a file the way the app does (Flysystem: directories on demand), returns its SHA-256. */
    protected function writeUpload(string $root, string $rel, string $bytes, ?int $mtime = null, ?int $mode = null): string
    {
        $path = $root.'/'.$rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $bytes);
        if ($mode !== null) {
            chmod($path, $mode);
        }
        if ($mtime !== null) {
            touch($path, $mtime);
        }

        return hash('sha256', $bytes);
    }

    /** @return string[] names of the retired application trees (_previous-*, _rolled-back-*, _stray-*) */
    protected function retiredTrees(): array
    {
        $names = [];
        foreach (scandir($this->releasesRoot) ?: [] as $name) {
            if (preg_match('/^_(previous|rolled-back|stray)-/', $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    protected function assertFileHasHash(string $path, string $sha256, string $message = ''): void
    {
        $this->assertFileExists($path, $message ?: 'missing: '.$path);
        $this->assertSame($sha256, hash_file('sha256', $path), $message ?: 'bytes differ: '.$path);
    }
}
