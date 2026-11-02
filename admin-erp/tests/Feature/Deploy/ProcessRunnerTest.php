<?php

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The deploy pipeline's process runner (deploy/remote/lib/process-runner.php, and deploy/README.md for why it has
 * two backends).
 *
 * This is a DEPLOYMENT primitive, not a backup one: `build` starts composer with it, `contract-check` starts the
 * contract script with it, and every future release goes through the same code. So it is tested as a primitive —
 * what it does with hostile arguments, with output on both streams, with a child that will not stop, with a binary
 * that is not there, and with a host that offers no way to start a process at all.
 *
 * WHAT THIS FILE CAN AND CANNOT COVER. The `proc_open` backend is exercised here for real. The `fork_exec` backend
 * cannot be: pcntl does not exist on Windows, where this suite runs, and pretending otherwise would be a test that
 * proves nothing. What IS covered here is that asking for a backend the host does not have fails cleanly instead of
 * calling a missing function — and the fork backend itself is exercised against the real primitive on the server by
 * `php process-runner.php selftest`, whose result is recorded in docs/BACKUP_AND_DISASTER_RECOVERY.md. The two
 * together are the coverage; neither alone is.
 */
class ProcessRunnerTest extends TestCase
{
    private static function load(): void
    {
        require_once base_path('deploy/remote/lib/process-runner.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::load();
    }

    /** Children run with -n so the host's own php.ini cannot print startup noise into what is being measured. */
    private function php(string $code, string ...$arguments): array
    {
        return array_merge([PHP_BINARY, '-n', '-r', $code], $arguments);
    }

    public function test_a_successful_command_reports_exit_zero_and_its_output(): void
    {
        $result = pfRunProcess($this->php('echo "hello";'));

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('hello', $result['stdout']);
        $this->assertSame('', $result['stderr']);
        $this->assertFalse($result['timed_out']);
        $this->assertNull($result['error']);
    }

    public function test_a_failing_command_is_never_reported_as_success(): void
    {
        $result = pfRunProcess($this->php('exit(7);'));

        $this->assertFalse($result['ok'], 'a non-zero exit must not be ok');
        $this->assertSame(7, $result['exit_code']);
    }

    public function test_stderr_is_captured_separately_from_stdout(): void
    {
        $result = pfRunProcess($this->php('echo "A"; fwrite(STDERR, "B"); echo "C"; fwrite(STDERR, "D");'));

        $this->assertSame('AC', $result['stdout']);
        $this->assertSame('BD', $result['stderr']);
        $this->assertSame(0, $result['exit_code']);
    }

    /**
     * The argument survives verbatim or the test fails. Each of these is something a shell would have eaten, and the
     * whole point of passing argv as an array is that none of it is ever parsed.
     */
    #[DataProvider('hostileArguments')]
    public function test_an_argument_is_passed_through_literally(string $argument): void
    {
        $result = pfRunProcess($this->php('echo $argv[1];', $argument));

        $this->assertSame(0, $result['exit_code'], 'stderr was: '.$result['stderr']);
        $this->assertSame($argument, $result['stdout']);
    }

    public static function hostileArguments(): array
    {
        return [
            'spaces' => ['one two three'],
            'double quotes' => ['he said "hello" loudly'],
            'single quotes' => ["it's an apostrophe"],
            'semicolon and pipe' => ['a;b|c'],
            'command substitution' => ['$(whoami) `id`'],
            'redirection' => ['a > b < c 2>&1'],
            'glob and ampersand' => ['*.php & echo nope'],
            'backslashes' => ['C:\\path\\to\\thing'],
            'newline' => ["first\nsecond"],
            'unicode' => ['প্রভাতফেরী — ünïcødé ✓'],
            'empty' => [''],
        ];
    }

    public function test_large_output_is_not_truncated_or_deadlocked(): void
    {
        $result = pfRunProcess($this->php('echo str_repeat("x", 3000000);'), null, 120);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(3000000, strlen($result['stdout']));
    }

    public function test_output_on_both_streams_at_once_does_not_deadlock(): void
    {
        // Enough on each stream to overflow a pipe buffer, which is the classic way a two-pipe implementation hangs.
        $result = pfRunProcess($this->php('echo str_repeat("o", 400000); fwrite(STDERR, str_repeat("e", 400000));'), null, 120);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(400000, strlen($result['stdout']));
        $this->assertSame(400000, strlen($result['stderr']));
    }

    public function test_a_child_that_will_not_finish_is_terminated_and_reported(): void
    {
        $started = microtime(true);
        $result = pfRunProcess($this->php('sleep(60);'), null, 2, ['grace_seconds' => 2]);
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result['timed_out']);
        $this->assertFalse($result['ok'], 'a timeout must never be reported as success');
        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('did not finish', (string) $result['error']);
        $this->assertLessThan(30, $elapsed, 'the timeout should stop the child, not wait for it');
    }

    public function test_a_missing_executable_is_a_clear_failure_before_anything_is_started(): void
    {
        $result = pfRunProcess([base_path('deploy/remote/lib/definitely-not-here'), 'argument']);

        $this->assertFalse($result['ok']);
        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('does not exist', (string) $result['error']);
    }

    public function test_an_empty_or_non_string_command_is_refused(): void
    {
        $this->assertStringContainsString('no command', (string) pfRunProcess([])['error']);
        /** @phpstan-ignore-next-line the point of the test is the wrong type */
        $this->assertStringContainsString('must be a string', (string) pfRunProcess([PHP_BINARY, 123])['error']);
    }

    public function test_a_working_directory_that_does_not_exist_is_refused(): void
    {
        $result = pfRunProcess($this->php('echo 1;'), base_path('deploy/no-such-directory'));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('working directory', (string) $result['error']);
    }

    public function test_the_working_directory_is_honoured(): void
    {
        $result = pfRunProcess($this->php('echo basename(getcwd());'), base_path('deploy'));

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('deploy', $result['stdout']);
    }

    public function test_named_secrets_never_appear_in_the_reported_output(): void
    {
        $secret = 'pf-test-secret-'.bin2hex(random_bytes(6));
        $result = pfRunProcess(
            $this->php('echo "before ".$argv[1]." after"; fwrite(STDERR, $argv[1]);', $secret),
            null,
            60,
            ['secrets' => [$secret]]
        );

        $this->assertStringNotContainsString($secret, $result['stdout']);
        $this->assertStringNotContainsString($secret, $result['stderr']);
        $this->assertSame('before <redacted> after', $result['stdout']);
        $this->assertSame('<redacted>', $result['stderr']);
    }

    public function test_redaction_ignores_values_too_short_to_be_secrets(): void
    {
        // Replacing a one-character "secret" would shred ordinary output while protecting nothing.
        $this->assertSame('a-b-c', pfRedact('a-b-c', ['-']));
        $this->assertSame('keep this', pfRedact('keep this', ['', 'ab']));
        $this->assertSame('<redacted>', pfRedact('abcd', ['abcd']));
    }

    public function test_a_host_with_no_way_to_start_a_process_fails_loudly(): void
    {
        $result = pfRunProcess($this->php('echo 1;'), null, 30, ['backend' => 'none']);

        $this->assertFalse($result['ok']);
        $this->assertSame(-1, $result['exit_code']);
        $this->assertSame('', $result['stdout']);
        $this->assertStringContainsString('neither proc_open nor', (string) $result['error']);
    }

    public function test_requesting_a_backend_this_host_lacks_fails_instead_of_calling_a_missing_function(): void
    {
        $missing = pfForkExecAvailable() ? 'proc_open' : 'fork_exec';
        if ($missing === 'proc_open' && function_exists('proc_open')) {
            $this->markTestSkipped('this host has both backends, so neither can be the missing one');
        }

        $result = pfRunProcess($this->php('echo 1;'), null, 30, ['backend' => $missing]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not', (string) $result['error']);
    }

    public function test_the_backend_choice_prefers_proc_open_and_reports_honestly(): void
    {
        $backend = pfProcessBackend();

        if (function_exists('proc_open')) {
            $this->assertSame('proc_open', $backend, 'proc_open must stay preferred wherever it works');
        } elseif (pfForkExecAvailable()) {
            $this->assertSame('fork_exec', $backend);
        } else {
            $this->assertSame('none', $backend);
        }

        // Whatever it chose, a real run must report the same backend it claimed.
        if ($backend !== 'none') {
            $this->assertSame($backend, pfRunProcess($this->php('echo 1;'))['backend']);
        }
    }

    public function test_the_capability_report_describes_this_host_without_leaking_anything(): void
    {
        $capabilities = pfProcessCapabilities();

        $this->assertArrayHasKey('proc_open', $capabilities['functions']);
        $this->assertArrayHasKey('pcntl_fork', $capabilities['functions']);
        $this->assertArrayHasKey('WNOHANG', $capabilities['constants']);
        $this->assertContains($capabilities['backend'], ['proc_open', 'fork_exec', 'none']);
        $this->assertSame(PHP_BINARY, $capabilities['php_binary']);

        // A capability report is read from cron output and pasted into reports, so it must carry no credential.
        $encoded = json_encode($capabilities);
        foreach (['DB_PASSWORD', 'MAIL_PASSWORD', 'APP_KEY', 'BACKUP_ENCRYPTION_KEY'] as $name) {
            $this->assertStringNotContainsString($name, (string) $encoded);
        }
    }

    public function test_the_fork_backend_runs_for_real_where_the_host_provides_it(): void
    {
        if (! pfForkExecAvailable()) {
            $this->markTestSkipped('pcntl fork/exec is not available here; the server self-test covers this backend (see the class docblock)');
        }

        $result = pfRunProcess($this->php('echo "forked"; fwrite(STDERR, "e"); exit(0);'), null, 60, ['backend' => 'fork_exec']);

        $this->assertSame('fork_exec', $result['backend']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('forked', $result['stdout']);
        $this->assertSame('e', $result['stderr']);
    }

    public function test_the_fork_backend_reports_a_signalled_child_where_the_host_provides_it(): void
    {
        if (! pfForkExecAvailable()) {
            $this->markTestSkipped('pcntl fork/exec is not available here; the server self-test covers this backend');
        }

        // Killed rather than exited: the exit code must still say failure, by the 128+signal convention.
        $result = pfRunProcess($this->php('posix_kill(posix_getpid(), SIGKILL); sleep(5);'), null, 30, ['backend' => 'fork_exec']);

        $this->assertFalse($result['ok']);
        $this->assertNotSame(0, $result['exit_code']);
    }
}
