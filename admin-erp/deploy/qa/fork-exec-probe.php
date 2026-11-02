<?php
/**
 * Finds out WHY a forked child's output does not reach the files it was pointed at. CLI only: 404 otherwise.
 *
 * Context: on this host proc_open is disabled, so the deploy pipeline's process runner falls back to
 * pcntl_fork + pcntl_exec. The fork, the wait, the timeout kill and the missing-binary check all work — but every
 * exec'd child comes back with exit code 255 and empty stdout and stderr, which is a child that never managed to
 * start rather than one that ran and said nothing.
 *
 * The suspicion is file descriptors: the child redirects its own stdout/stderr by closing fd 1 and 2 and opening
 * files, which relies on the kernel handing back the lowest free descriptor AND on those descriptors surviving
 * execve. If PHP opens streams close-on-exec, they do not survive, and the exec'd program starts with no stdout and
 * no stderr at all — which is exactly what exit 255 and no output look like.
 *
 * Each case below isolates one link in that chain, so the fix is chosen from evidence instead of guessed:
 *
 *   php fork-exec-probe.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

$dir = sys_get_temp_dir().'/pf-probe-'.bin2hex(random_bytes(4));
@mkdir($dir, 0700, true);
$php = PHP_BINARY;
$results = [];

/** Fork, let the child set itself up however $child says, and report what came back. */
function attempt(string $label, callable $child, string $out, string $err, array &$results): void
{
    @file_put_contents($out, '');
    @file_put_contents($err, '');

    $pid = pcntl_fork();
    if ($pid === -1) {
        $results[$label] = ['error' => 'fork failed'];

        return;
    }
    if ($pid === 0) {
        $child();
        exit(126);               // only reached if the child's own code returned
    }

    $status = 0;
    pcntl_waitpid($pid, $status);

    $results[$label] = [
        'exited' => pcntl_wifexited($status),
        'exit_code' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
        'signal' => pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null,
        'stdout' => substr((string) @file_get_contents($out), 0, 200),
        'stderr' => substr((string) @file_get_contents($err), 0, 300),
    ];
}

$out = $dir.'/o';
$err = $dir.'/e';

// 1. The current approach, exactly as the runner does it.
attempt('close_then_fopen_then_exec_php_n', function () use ($php, $out, $err) {
    fclose(STDIN);
    $i = @fopen('/dev/null', 'rb');
    fclose(STDOUT);
    $o = @fopen($out, 'wb');
    fclose(STDERR);
    $e = @fopen($err, 'wb');
    unset($i, $o, $e);
    @pcntl_exec($php, ['-n', '-r', 'echo "CHILD-OUT"; fwrite(STDERR, "CHILD-ERR");']);
    exit(127);
}, $out, $err, $results);

// 2. Same, but the child writes a marker through its redirected handles BEFORE exec. If the marker lands and the
//    exec'd program's output does not, the redirect worked and was lost AT exec — i.e. close-on-exec.
attempt('marker_written_before_exec', function () use ($php, $out, $err) {
    fclose(STDIN);
    $i = @fopen('/dev/null', 'rb');
    fclose(STDOUT);
    $o = @fopen($out, 'wb');
    fclose(STDERR);
    $e = @fopen($err, 'wb');
    fwrite($o, 'MARKER-STDOUT|');
    fwrite($e, 'MARKER-STDERR|');
    fflush($o);
    fflush($e);
    unset($i, $o, $e);
    @pcntl_exec($php, ['-n', '-r', 'echo "CHILD-OUT";']);
    exit(127);
}, $out, $err, $results);

// 3. Without -n, in case this build needs its php.ini to start at all.
attempt('without_minus_n', function () use ($php, $out, $err) {
    fclose(STDIN);
    $i = @fopen('/dev/null', 'rb');
    fclose(STDOUT);
    $o = @fopen($out, 'wb');
    fclose(STDERR);
    $e = @fopen($err, 'wb');
    unset($i, $o, $e);
    @pcntl_exec($php, ['-r', 'echo "CHILD-OUT";']);
    exit(127);
}, $out, $err, $results);

// 4. No redirect at all: does the exec'd child even run? Its output goes to this script's own stdout, so seeing
//    CHILD-OUT in the cron log next to this JSON is the answer.
attempt('exec_with_no_redirect', function () use ($php) {
    @pcntl_exec($php, ['-n', '-r', 'echo "CHILD-OUT-INHERITED";']);
    exit(127);
}, $out, $err, $results);

// 5. Let /bin/sh open the files itself, after the fork. A shell opens its redirections in the child, so this case
//    cannot be affected by close-on-exec — and it is the fallback if the evidence points that way.
attempt('sh_opens_the_files_itself', function () use ($out, $err) {
    @pcntl_exec('/bin/sh', ['-c', 'echo SH-OUT > '.escapeshellarg($out).'; echo SH-ERR > '.escapeshellarg($err)]);
    exit(127);
}, $out, $err, $results);

// 6. And the same shape the runner would actually need: a real program, with its streams redirected by the shell.
attempt('sh_runs_php_with_redirection', function () use ($php, $out, $err) {
    @pcntl_exec('/bin/sh', ['-c', escapeshellarg($php).' -n -r '.escapeshellarg('echo "CHILD-OUT"; fwrite(STDERR, "CHILD-ERR");').' > '.escapeshellarg($out).' 2> '.escapeshellarg($err)]);
    exit(127);
}, $out, $err, $results);

@unlink($out);
@unlink($err);
@rmdir($dir);

echo json_encode([
    'php_binary' => $php,
    'sh_present' => is_file('/bin/sh'),
    'dev_null_writable' => is_writable('/dev/null'),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
