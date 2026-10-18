<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * The production acceptance kit of the gated volunteer Messenger group (deploy/qa/volunteer-messenger-qa.php).
 *
 * Like every kit that deletes, it refuses to `purge` on the live host unless it was uploaded as `_qa_<hex>.php`, a name made for one
 * session (see QaKitGuardsTest for why). The gate sits before anything is loaded, so these tests run the real file without an
 * application or a database. The rest of what the kit promises is read from its source: what it may match, what it may print.
 */
class VolunteerMessengerKitTest extends TestCase
{
    private const KIT = 'volunteer-messenger-qa.php';

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

    /** @return array{0: int, 1: string} */
    private function runKit(string $asName, string ...$args): array
    {
        $dir = sys_get_temp_dir().'/pf-vmg-kit-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        copy(base_path('deploy/qa/'.self::KIT), $dir.'/'.$asName);

        $env = array_merge(getenv(), ['QA_FORCE_PRODUCTION_GUARDS' => '1', 'QA_APP' => $dir.'/no-such-application']);
        $process = proc_open([PHP_BINARY, $dir.'/'.$asName, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    public function test_purge_is_refused_on_production_from_a_fixed_name(): void
    {
        foreach (['_qa_messenger.php', 'kit.php', '_qa_1234567.php', '_qa_messenger_0123abcd.PHP', 'volunteer-messenger-qa.php'] as $name) {
            [$code, $output] = $this->runKit($name, 'purge', 'abcdef123456', 'go');

            $this->assertSame(2, $code, "{$name} must refuse: {$output}");
            $this->assertStringContainsString('refusing on production', $output, $name);
            $this->assertStringContainsString('nothing was removed', $output);
        }
    }

    public function test_a_one_off_name_gets_past_the_gate(): void
    {
        foreach (['_qa_0123456789ab.php', '_qa_messenger_0123abcd.php', '_qa_deadbeef.php'] as $name) {
            [$code, $output] = $this->runKit($name, 'purge', 'abcdef123456', 'go');

            $this->assertStringNotContainsString('refusing on production', $output, "{$name}: {$output}");
            $this->assertNotSame(2, $code, "{$name} stopped at the gate");
        }
    }

    public function test_read_only_modes_and_the_snapshot_are_not_gated_by_name(): void
    {
        foreach (['inventory', 'settings'] as $mode) {
            [, $output] = $this->runKit('kit.php', $mode);
            $this->assertStringNotContainsString('refusing on production', $output, $mode);
        }
        [, $output] = $this->runKit('kit.php', 'snapshot', 'abcdef123456');
        $this->assertStringNotContainsString('refusing on production', $output);
    }

    public function test_a_bad_mode_or_tag_is_a_usage_error_and_touches_nothing(): void
    {
        [$code] = $this->runKit('_qa_deadbeef.php', 'wipe');
        $this->assertSame(2, $code);

        [$code, $output] = $this->runKit('_qa_deadbeef.php', 'purge', 'x', 'go');
        $this->assertSame(2, $code);
        $this->assertStringContainsString('usage', $output);
    }

    public function test_the_kit_matches_only_marked_applications_and_never_prints_the_invite(): void
    {
        $source = (string) file_get_contents(base_path('deploy/qa/'.self::KIT));

        // Two markers, both required: a real applicant carries neither.
        $this->assertStringContainsString("->where('applicant_name', 'like', QA_NAME_MARK.'%')", $source);
        $this->assertStringContainsString("->where('applicant_email', 'like', '%'.QA_EMAIL_MARK.'%')", $source);
        // The only delete on applications is by the marked ids; an empty list deletes nothing.
        $this->assertSame(1, substr_count($source, "DB::table('job_applications')->whereIn('id', \$qa)->delete()"));
        $this->assertStringContainsString("if (\$ids === []) {\n        return [];\n    }", $source, 'filesOf([]) must be empty, never "every application"');
        // It reports the saved invite only as a digest of its token.
        $this->assertStringContainsString("substr(hash('sha256', \$token), 0, 8)", $source);
        $this->assertDoesNotMatchRegularExpression('/[\'"]link[\'"]\s*=>|[\'"]url[\'"]\s*=>\s*\$/', $source, 'no output field may carry the link itself');
        // Settings rows are compared apart: setting the real link at the end of the acceptance is intended.
        $this->assertStringContainsString("whereNotIn('key', MESSENGER_KEYS)", $source);
    }
}
