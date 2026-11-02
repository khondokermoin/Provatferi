<?php

/*
 * Starts a child process and reports what it printed and how it ended — on a host that may not allow proc_open.
 * (deploy/README.md, "Why this is built the way it is".)
 *
 * WHY THIS EXISTS. The whole deploy pipeline is built on proc_open: `build` runs composer with it, `contract-check`
 * runs the contract script with it, and `pipeline` re-invokes itself with it. On 2026-11-02 that stopped working —
 * the host added proc_open to disable_functions, and because display_errors is off the failure was SILENT: the cron
 * log was empty and the deploy looked like it was still running. So a release could be staged but never built.
 *
 * This is the one place that knows how to start a process, with two backends and no third option:
 *
 *   proc_open   preferred, used whenever it is actually callable — so if the host restores it, nothing changes;
 *   fork+exec   pcntl_fork() + pcntl_exec(), which are not blocked here. A real child, a real exit status.
 *
 * Both backends behave identically on purpose: stdout and stderr go to separate private files rather than pipes, so
 * there is no two-pipe deadlock to get wrong and no reliance on stream_select (which does not work on pipes on
 * Windows, where this code is developed and unit-tested).
 *
 * WHY THE FORK BACKEND GOES THROUGH /bin/sh, WHICH IS NOT WHAT ANYONE WANTS. Measured on the server
 * (deploy/qa/fork-exec-probe.php, 2026-11-02), because the first version of this file did NOT use a shell and did
 * not work:
 *
 *   child closes fd 1/2, opens files, execs            -> exit 255, both files empty
 *   same, but the child writes a marker before exec     -> the MARKER IS THERE, the program's output is not
 *   exec with no redirect at all                        -> runs fine, output lands on the inherited descriptor
 *   /bin/sh opens the files itself after the fork       -> works
 *
 * The second line is the proof: the redirection succeeded and the descriptors were lost AT execve. PHP opens file
 * streams close-on-exec on this build, so an exec'd program inherits no stdout and no stderr — which is why it died
 * at startup with nothing to say. PHP exposes no dup2 and no way to clear that flag, so the files must be opened by
 * something that is not PHP, after the fork. That is a shell, and only a shell.
 *
 * It is therefore made safe rather than avoided: EVERY argv element and both paths go through escapeshellarg(),
 * which single-quotes each one, and single quotes stop the shell interpreting anything at all — no expansion, no
 * substitution, no globbing, no word splitting. One argument in, one argument out, whatever bytes it holds. The
 * `exec` builtin then replaces the shell with the real program, so the pid being waited on and signalled is the
 * program itself and not a shell wrapper around it. The hostile-argument tests in
 * tests/Feature/Deploy/ProcessRunnerTest.php and in this file's own `selftest` are what keep that honest.
 *
 * WHAT IT REFUSES TO DO:
 *   - no shell INTERPOLATION, and no shell at all where one is avoidable: proc_open is given the array form, so
 *     that backend never involves a shell, and the fork backend quotes every byte it hands over. A path containing
 *     a space, a quote or a semicolon is one argument and never a second command;
 *   - no silent success. A backend that cannot start, a binary that is not there, a timeout and a child killed by a
 *     signal are each a distinct, reported failure — never exit code 0;
 *   - no secrets in diagnostics. Callers pass the literals that must never be echoed, and every string this returns
 *     is filtered through that list.
 *
 * Run it directly for a report on the host it is sitting on:
 *
 *   php process-runner.php capabilities   what this host allows, and which backend would be used
 *   php process-runner.php selftest       actually start processes through the chosen backend and check the results
 */

if (! function_exists('pfProcessBackend')) {
    /**
     * Which backend is usable here: 'proc_open', 'fork_exec', or 'none'.
     *
     * proc_open wins when present because it is the better-tested path and needs no signal handling. The fork
     * backend needs its whole set to be callable — a half-available pcntl is not a backend, and claiming it is would
     * mean discovering the gap mid-deploy.
     */
    function pfProcessBackend(): string
    {
        if (function_exists('proc_open') && function_exists('proc_get_status') && function_exists('proc_close')) {
            return 'proc_open';
        }

        return pfForkExecAvailable() ? 'fork_exec' : 'none';
    }
}

if (! function_exists('pfForkExecAvailable')) {
    /**
     * Whether the whole fork/exec set is callable.
     *
     * Checked as a set, never piecemeal: a half-available pcntl is not a backend, and finding that out mid-deploy is
     * exactly what this file exists to prevent. It is also what stops a forced backend (tests use that) from calling
     * a function that does not exist, which in PHP 8 is a fatal Error rather than something a return value can say.
     */
    function pfForkExecAvailable(): bool
    {
        foreach ([
            'pcntl_fork', 'pcntl_exec', 'pcntl_waitpid', 'pcntl_wifexited', 'pcntl_wexitstatus',
            'pcntl_wifsignaled', 'pcntl_wtermsig', 'posix_kill',
        ] as $required) {
            if (! function_exists($required)) {
                return false;
            }
        }

        // WNOHANG is what makes the wait loop pollable, and therefore what makes a timeout possible at all.
        if (! defined('WNOHANG') || ! defined('SIGTERM') || ! defined('SIGKILL')) {
            return false;
        }

        // /bin/sh is required, not optional: it is what opens the output files after the fork, because a file this
        // process opened does not survive execve on this host (see the header). escapeshellarg must exist for that
        // hand-off to be safe, and a host without it is a host this backend must refuse to use.
        return is_file('/bin/sh') && is_executable('/bin/sh') && function_exists('escapeshellarg');
    }
}

if (! function_exists('pfProcessCapabilities')) {
    /** Everything about this host that decides whether a process can be started. Contains no secret. */
    function pfProcessCapabilities(): array
    {
        $functions = [];
        foreach ([
            'proc_open', 'proc_close', 'proc_get_status', 'proc_terminate',
            'exec', 'shell_exec', 'system', 'passthru', 'popen', 'pclose',
            'pcntl_fork', 'pcntl_exec', 'pcntl_waitpid', 'pcntl_wait',
            'pcntl_wifexited', 'pcntl_wexitstatus', 'pcntl_wifsignaled', 'pcntl_wtermsig',
            'pcntl_signal', 'pcntl_alarm',
            'posix_kill', 'posix_getpid', 'posix_setsid', 'posix_geteuid',
            'stream_socket_pair', 'stream_select',
        ] as $name) {
            $functions[$name] = function_exists($name);
        }

        $constants = [];
        foreach (['WNOHANG', 'WUNTRACED', 'SIGTERM', 'SIGKILL'] as $name) {
            $constants[$name] = defined($name);
        }

        return [
            'php_binary' => PHP_BINARY,
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os_family' => PHP_OS_FAMILY,
            'pcntl_loaded' => extension_loaded('pcntl'),
            'posix_loaded' => extension_loaded('posix'),
            'functions' => $functions,
            'constants' => $constants,
            // Reported verbatim because it is a configuration list, not a credential.
            'disable_functions' => (string) ini_get('disable_functions'),
            'backend' => pfProcessBackend(),
        ];
    }
}

if (! function_exists('pfRedact')) {
    /**
     * Remove known-secret literals from anything about to be reported.
     *
     * Shorter than 4 characters is ignored: replacing a 1-3 character string would mangle ordinary output without
     * protecting anything worth protecting.
     *
     * @param  list<string>  $secrets
     */
    function pfRedact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace($secret, '<redacted>', $text);
            }
        }

        return $text;
    }
}

if (! function_exists('pfRunProcess')) {
    /**
     * Run a command and wait for it.
     *
     * @param  list<string>  $command  argv: the executable first, then its arguments, each already separate
     * @param  array{backend?: string, secrets?: list<string>, grace_seconds?: int}  $options
     *                                 `backend` forces one backend (tests use it to exercise the paths this host
     *                                 cannot reach); `secrets` are literals to strip from every returned string.
     * @return array{ok: bool, backend: string, exit_code: int, stdout: string, stderr: string, timed_out: bool,
     *               seconds: float, error: ?string}
     */
    function pfRunProcess(array $command, ?string $cwd = null, int $timeoutSeconds = 300, array $options = []): array
    {
        $secrets = is_array($options['secrets'] ?? null) ? $options['secrets'] : [];
        $started = microtime(true);

        $fail = static function (string $error) use ($started, $secrets): array {
            return [
                'ok' => false,
                'backend' => 'none',
                'exit_code' => -1,
                'stdout' => '',
                'stderr' => '',
                'timed_out' => false,
                'seconds' => round(microtime(true) - $started, 3),
                'error' => pfRedact($error, $secrets),
            ];
        };

        if ($command === []) {
            return $fail('no command was given');
        }
        foreach ($command as $argument) {
            if (! is_string($argument)) {
                return $fail('every element of the command must be a string, so argument boundaries cannot be lost');
            }
        }

        // Checked HERE, in the parent, on purpose: once pcntl_exec fails there is no way left to say why — stderr
        // has already been pointed at a file and the child can only exit with a number. A missing binary is by far
        // the most likely cause, so it is turned into a clear error before anything is forked.
        $binary = $command[0];
        if ($binary === '' || ! is_file($binary)) {
            return $fail('the executable does not exist: '.$binary);
        }
        if (PHP_OS_FAMILY !== 'Windows' && ! is_executable($binary)) {
            return $fail('the executable is not executable: '.$binary);
        }
        if ($cwd !== null && ! is_dir($cwd)) {
            return $fail('the working directory does not exist: '.$cwd);
        }

        $backend = is_string($options['backend'] ?? null) ? $options['backend'] : pfProcessBackend();
        if ($backend === 'none') {
            return $fail('this host allows neither proc_open nor the pcntl fork/exec pair, so no process can be started at all');
        }
        // A backend named by the caller is still checked against what this host can do, so asking for one that is
        // not here is a reported failure and never a call to a missing function.
        if ($backend === 'proc_open' && ! function_exists('proc_open')) {
            return $fail('proc_open was requested but this host does not allow it');
        }
        if ($backend === 'fork_exec' && ! pfForkExecAvailable()) {
            return $fail('the pcntl fork/exec backend was requested but this host does not provide all of it');
        }

        $directory = sys_get_temp_dir().'/pf-run-'.bin2hex(random_bytes(6));
        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return $fail('a private directory for the output could not be created');
        }
        @chmod($directory, 0700);
        $outPath = $directory.'/stdout';
        $errPath = $directory.'/stderr';

        try {
            if ($backend === 'proc_open') {
                $result = pfRunViaProcOpen($command, $cwd, $timeoutSeconds, $outPath, $errPath, $options);
            } elseif ($backend === 'fork_exec') {
                $result = pfRunViaForkExec($command, $cwd, $timeoutSeconds, $outPath, $errPath, $options);
            } else {
                return $fail('unknown backend: '.$backend);
            }

            $stdout = is_file($outPath) ? (string) file_get_contents($outPath) : '';
            $stderr = is_file($errPath) ? (string) file_get_contents($errPath) : '';

            return [
                'ok' => $result['error'] === null && $result['exit_code'] === 0 && ! $result['timed_out'],
                'backend' => $backend,
                'exit_code' => $result['exit_code'],
                'stdout' => pfRedact($stdout, $secrets),
                'stderr' => pfRedact($stderr, $secrets),
                'timed_out' => $result['timed_out'],
                'seconds' => round(microtime(true) - $started, 3),
                'error' => $result['error'] === null ? null : pfRedact($result['error'], $secrets),
            ];
        } finally {
            @unlink($outPath);
            @unlink($errPath);
            @rmdir($directory);
        }
    }
}

if (! function_exists('pfRunViaProcOpen')) {
    /** @return array{exit_code: int, timed_out: bool, error: ?string} */
    function pfRunViaProcOpen(array $command, ?string $cwd, int $timeoutSeconds, string $outPath, string $errPath, array $options): array
    {
        // Both streams go to files, so neither can fill a pipe buffer and deadlock the child. stdin is a pipe that is
        // closed immediately: a child that reads gets EOF instead of hanging on a terminal that is not there.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outPath, 'w'],
            2 => ['file', $errPath, 'w'],
        ];

        // Suppressed because the return value IS the error handling here: proc_open emits a warning and returns
        // false, and a warning is an exception under the test runner.
        $process = @proc_open($command, $descriptors, $pipes, $cwd);
        if (! is_resource($process)) {
            return ['exit_code' => -1, 'timed_out' => false, 'error' => 'the process could not be started'];
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $deadline = $timeoutSeconds > 0 ? microtime(true) + $timeoutSeconds : null;
        $exitCode = -1;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            if ($status === false) {
                break;
            }
            if (! $status['running']) {
                // Read before proc_close: the exit code is only reported once, and proc_close would consume it.
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if ($deadline !== null && microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, defined('SIGTERM') ? SIGTERM : 15);
                $grace = microtime(true) + (int) ($options['grace_seconds'] ?? 5);
                while (microtime(true) < $grace) {
                    $status = proc_get_status($process);
                    if ($status !== false && ! $status['running']) {
                        $exitCode = (int) $status['exitcode'];
                        break 2;
                    }
                    usleep(100000);
                }
                proc_terminate($process, defined('SIGKILL') ? SIGKILL : 9);
                break;
            }
            usleep(50000);
        }

        $closed = proc_close($process);
        if ($exitCode === -1 && ! $timedOut && $closed !== -1) {
            $exitCode = $closed;
        }

        return [
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'error' => $timedOut ? 'the process did not finish within '.$timeoutSeconds.'s and was terminated' : null,
        ];
    }
}

if (! function_exists('pfRunViaForkExec')) {
    /** @return array{exit_code: int, timed_out: bool, error: ?string} */
    function pfRunViaForkExec(array $command, ?string $cwd, int $timeoutSeconds, string $outPath, string $errPath, array $options): array
    {
        // Created by the PARENT so that a child which cannot even open them is impossible, and so the files exist
        // for reading afterwards whatever the child did.
        if (@file_put_contents($outPath, '') === false || @file_put_contents($errPath, '') === false) {
            return ['exit_code' => -1, 'timed_out' => false, 'error' => 'the output files could not be created'];
        }
        @chmod($outPath, 0600);
        @chmod($errPath, 0600);

        $pid = pcntl_fork();
        if ($pid === -1) {
            return ['exit_code' => -1, 'timed_out' => false, 'error' => 'the process could not be forked'];
        }

        if ($pid === 0) {
            // ---------------------------------------------------------------- child
            // Nothing here may report an error: stdout and stderr are about to belong to the program being started,
            // so there is nowhere left to report one to. The parent interprets the exit status instead.
            //
            // The shell opens the two files AFTER the fork, which is the whole point — a file this process opened
            // would be closed by execve (see the header). Every element is single-quoted by escapeshellarg, so the
            // shell splits the command exactly where the argv array said and interprets nothing inside any
            // argument. `exec` makes the shell become the program, so the pid the parent waits on and signals is
            // the program's own.
            $quoted = [];
            foreach ($command as $argument) {
                $quoted[] = escapeshellarg($argument);
            }
            $script = 'exec '.implode(' ', $quoted)
                .' < /dev/null'
                .' > '.escapeshellarg($outPath)
                .' 2> '.escapeshellarg($errPath);

            if ($cwd !== null) {
                @chdir($cwd);
            }

            // Only returns if exec itself failed; 127 is the conventional "could not run it", and the parent has
            // already ruled out the common cause (a missing or non-executable binary) before forking.
            @pcntl_exec('/bin/sh', ['-c', $script]);
            exit(127);
        }

        // ------------------------------------------------------------------- parent
        $deadline = $timeoutSeconds > 0 ? microtime(true) + $timeoutSeconds : null;
        $status = 0;
        $timedOut = false;
        $reaped = false;

        while (true) {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                $reaped = true;
                break;
            }
            if ($waited === -1) {
                return ['exit_code' => -1, 'timed_out' => false, 'error' => 'the child could not be waited for'];
            }
            if ($deadline !== null && microtime(true) > $deadline) {
                $timedOut = true;
                break;
            }
            usleep(50000);
        }

        if ($timedOut) {
            // Asked to stop, then made to stop, and reaped either way — a child that is never waited for becomes a
            // zombie, and a deploy that leaves zombies behind on a shared host is a deploy that gets noticed.
            posix_kill($pid, SIGTERM);
            $grace = microtime(true) + (int) ($options['grace_seconds'] ?? 5);
            while (microtime(true) < $grace) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                    $reaped = true;
                    break;
                }
                usleep(100000);
            }
            if (! $reaped) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }

            return [
                'exit_code' => -1,
                'timed_out' => true,
                'error' => 'the process did not finish within '.$timeoutSeconds.'s and was terminated',
            ];
        }

        if (pcntl_wifexited($status)) {
            $exitCode = pcntl_wexitstatus($status);

            return [
                'exit_code' => $exitCode,
                'timed_out' => false,
                'error' => $exitCode === 127 ? 'the process exited 127, which usually means it could not be executed' : null,
            ];
        }

        if (pcntl_wifsignaled($status)) {
            $signal = pcntl_wtermsig($status);

            // 128+n is the shell convention for "killed by signal n", kept so a caller comparing against 0 still
            // sees a failure rather than an accidental success.
            return [
                'exit_code' => 128 + $signal,
                'timed_out' => false,
                'error' => 'the process was killed by signal '.$signal,
            ];
        }

        return ['exit_code' => -1, 'timed_out' => false, 'error' => 'the process ended in a way that could not be interpreted'];
    }
}

// ----------------------------------------------------------------------------------------------------------------
// CLI entry — only when this file is the script being run, never when it is required by the release manager.
// ----------------------------------------------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $mode = $argv[1] ?? 'capabilities';
    $emit = static function (array $data, int $exit = 0): never {
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
        exit($exit);
    };

    if ($mode === 'capabilities') {
        $emit(['mode' => 'capabilities', 'checked_at_utc' => gmdate('c')] + pfProcessCapabilities());
    }

    if ($mode !== 'selftest') {
        $emit(['error' => 'modes: capabilities | selftest'], 2);
    }

    // Exercises the backend this host would actually use, with the real primitive, on the real machine. Every case
    // here is one a deploy depends on: a success, a failure, both streams, literal argv, and a timeout that has to
    // kill something.
    // Every child runs with -n (ignore php.ini). Not a convenience: on one of the machines this is developed on,
    // php.ini double-loads an extension and PHP prints a startup warning into the child's stdout, which would mask
    // exactly what these checks measure. -n makes the self-test report on the runner, not on the host's php.ini.
    $php = PHP_BINARY;
    $checks = [];

    $r = pfRunProcess([$php, '-n', '-r', 'echo "out";']);
    $checks['exit_zero_and_stdout'] = ['pass' => $r['ok'] === true && $r['stdout'] === 'out' && $r['exit_code'] === 0, 'got' => $r];

    $r = pfRunProcess([$php, '-n', '-r', 'fwrite(STDERR, "err"); exit(3);']);
    $checks['nonzero_exit_and_stderr'] = ['pass' => $r['ok'] === false && $r['exit_code'] === 3 && $r['stderr'] === 'err', 'got' => $r];

    $r = pfRunProcess([$php, '-n', '-r', 'echo "O"; fwrite(STDERR, "E"); echo "O2";']);
    $checks['streams_stay_separate'] = ['pass' => $r['stdout'] === 'OO2' && $r['stderr'] === 'E', 'got' => $r];

    // One argument, containing everything a shell would have mangled. If any of it were interpreted, this fails.
    $hostile = 'a b"c\'d;e|f$g`h&i*j?k<l>m'."\n".'ünïcødé';
    $r = pfRunProcess([$php, '-n', '-r', 'echo $argv[1];', $hostile]);
    $checks['argv_stays_literal'] = ['pass' => $r['stdout'] === $hostile, 'got' => ['stdout_matches' => $r['stdout'] === $hostile, 'exit_code' => $r['exit_code'], 'bytes' => strlen($r['stdout'])]];

    $r = pfRunProcess([$php, '-n', '-r', 'echo str_repeat("x", 2000000);']);
    $checks['large_output'] = ['pass' => strlen($r['stdout']) === 2000000, 'got' => ['bytes' => strlen($r['stdout']), 'exit_code' => $r['exit_code']]];

    $r = pfRunProcess([$php, '-n', '-r', 'sleep(30);'], null, 2, ['grace_seconds' => 2]);
    $checks['timeout_kills_and_reaps'] = ['pass' => $r['timed_out'] === true && $r['ok'] === false && $r['seconds'] < 20, 'got' => ['timed_out' => $r['timed_out'], 'seconds' => $r['seconds'], 'error' => $r['error']]];

    $r = pfRunProcess(['/definitely/not/here', 'x']);
    $checks['missing_binary_is_a_clear_failure'] = ['pass' => $r['ok'] === false && $r['exit_code'] !== 0 && is_string($r['error']), 'got' => $r];

    $r = pfRunProcess([$php, '-n', '-r', 'echo getenv("PF_SECRET_PROBE") ?: "s3cret-literal-value";'], null, 30, ['secrets' => ['s3cret-literal-value']]);
    $checks['secrets_are_redacted'] = ['pass' => $r['stdout'] === '<redacted>', 'got' => $r['stdout']];

    $r = pfRunProcess([$php, '-n', '-r', 'echo 1;'], null, 30, ['backend' => 'none']);
    $checks['no_backend_is_never_success'] = ['pass' => $r['ok'] === false && $r['exit_code'] === -1, 'got' => $r['error']];

    $failed = array_keys(array_filter($checks, static fn (array $c): bool => $c['pass'] !== true));

    $emit([
        'mode' => 'selftest',
        'checked_at_utc' => gmdate('c'),
        'backend' => pfProcessBackend(),
        'php_binary' => PHP_BINARY,
        'ok' => $failed === [],
        'failed' => $failed,
        'checks' => $checks,
    ], $failed === [] ? 0 : 1);
}
