<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * The production-name gate on every QA kit's `cleanup` (2026-10-09).
 *
 * An orphan cron that the hosting API could neither list nor delete kept running `_qa_registry.php cleanup` every minute, and wiped QA rows
 * the day a kit was uploaded under that fixed name again. The kits now refuse `cleanup` on the live host unless they were uploaded as
 * `_qa_<hex>.php`, a name made for one session. The gate sits before anything is loaded, so these tests run the real kit files without an
 * application or a database (`QA_APP` points nowhere: a kit that gets past the gate dies on the missing autoloader instead).
 */
class QaKitGuardsTest extends TestCase
{
    private const KITS = [
        'membership-registry-qa.php',
        'membership-fees-qa.php',
        'membership-cache-qa.php',
        'upload-forms-qa.php',
        'uploads-race-qa.php',
    ];

    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    /** Runs a copy of the kit, saved under $asName, with the production rule forced on. @return array{0: int, 1: string} */
    private function runKit(string $kit, string $asName, string ...$args): array
    {
        $dir = sys_get_temp_dir().'/pf-qa-kit-gate-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        copy(base_path('deploy/qa/'.$kit), $dir.'/'.$asName);

        $env = array_merge(getenv(), ['QA_FORCE_PRODUCTION_GUARDS' => '1', 'QA_APP' => $dir.'/no-such-application']);
        $process = proc_open([PHP_BINARY, $dir.'/'.$asName, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    public function test_every_kit_refuses_cleanup_on_production_from_a_fixed_name(): void
    {
        foreach (self::KITS as $kit) {
            foreach (['_qa_registry.php', '_qa_race.php', 'kit.php', '_qa_1234567.php', '_qa_registry_0123abcd.PHP'] as $name) {
                [$code, $output] = $this->runKit($kit, $name, 'cleanup');

                $this->assertSame(2, $code, "{$kit} as {$name} must refuse: {$output}");
                $this->assertStringContainsString('refusing on production', $output, "{$kit} as {$name}");
                $this->assertStringContainsString('nothing was removed', $output);
            }
        }
    }

    public function test_a_one_off_name_gets_past_the_gate(): void
    {
        foreach (self::KITS as $kit) {
            foreach (['_qa_0123456789ab.php', '_qa_race_0123abcd.php', '_qa_deadbeef.php'] as $name) {
                [$code, $output] = $this->runKit($kit, $name, 'cleanup');

                $this->assertStringNotContainsString('refusing on production', $output, "{$kit} as {$name}: {$output}");
                $this->assertNotSame(2, $code, "{$kit} as {$name} stopped at the gate");
            }
        }
    }

    public function test_other_modes_are_not_gated_by_name(): void
    {
        foreach (self::KITS as $kit) {
            [, $output] = $this->runKit($kit, '_qa_registry.php', 'snapshot');
            $this->assertStringNotContainsString('refusing on production', $output, $kit);
        }
    }

    public function test_the_gate_is_off_for_local_runs(): void
    {
        $dir = sys_get_temp_dir().'/pf-qa-kit-gate-local-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        copy(base_path('deploy/qa/membership-registry-qa.php'), $dir.'/anything.php');

        $env = array_merge(getenv(), ['QA_APP' => $dir.'/no-such-application']);
        unset($env['QA_FORCE_PRODUCTION_GUARDS']);
        $process = proc_open([PHP_BINARY, $dir.'/anything.php', 'cleanup'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Locally the live-host directory does not exist, so there is no gate (it dies on the missing application instead).
        $this->assertDirectoryDoesNotExist('/home/u951246149/domains');
        $this->assertStringNotContainsString('refusing on production', $output);
    }
}
