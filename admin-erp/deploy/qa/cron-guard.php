<?php

declare(strict_types=1);

/**
 * Cron-job guard for QA and deploy sessions on the Hostinger account. A small CLI plus pure functions
 * (tests/Feature/Deploy/CronGuardTest.php), run on the OPERATOR'S machine against JSON and text that the hosting API returned.
 *
 * WHY (2026-10-09). A minutely `_qa_registry.php cleanup` cron was created on 2026-10-08; the API's answer was a record with every field
 * empty (uid, username, time, command). Nothing checked it. The job existed anyway, was never listed, and could never be deleted — a day later it
 * removed QA rows one minute after they were made, because a kit had been uploaded under the same fixed name. Cleaning it up showed how the
 * scheduler really works:
 *
 *   - The account's jobs are lines of a crontab that lives OUTSIDE the account's jail (there is no `crontab` binary and /var/spool/cron is
 *     empty). Every line runs
 *         /bin/sh -c /usr/bin/flock -w 1 /tmp/cron_lock_<uid> timeout -s 9 1800 <command> > ~/.logs/cronjob_<uid> 2>&1 # CRONJOBID:<uid>
 *   - `hosting_cron-jobs_list` and `hosting_cron-jobs_delete` work from the control plane's own records, not from that crontab. A job whose
 *     create came back blank exists in the scheduler but not in those records: it is never listed, `delete` answers 202 "Request accepted"
 *     and changes nothing (it answers the same for a uid that never existed), and the job keeps running. The same turned out to be true of
 *     a second job (a read-only directory listing) and of three one-shot jobs whose logs are still on disk.
 *   - The one place that always tells the truth is ~/.logs: a live job rewrites its `cronjob_<uid>` file on every run. So the list API is
 *     evidence that a job EXISTS, never that it does not.
 *
 * WHAT THIS DOES
 *   new-session                                  a fresh id (8 hex) for this session: the kit is uploaded as _qa_<id>.php and every cron
 *                                                command carries <id>, so a job can always be tied back to its session.
 *   confirm-create <ledger> <create.json> "<schedule>" <id>
 *                                                the create response must carry a uid, username, time and command, the schedule must be the one
 *                                                asked for and the command must carry <id>. Anything less is a FAILED create: the job may exist
 *                                                and be unreachable, so nothing is "armed" and nothing may be assumed — it is written to the ledger
 *                                                as `unconfirmed` and reconcile fails until a person resolves it.
 *   reconcile <ledger> <list.json> <logs.txt> [<known.json>]
 *                                                lists everything the ledger, the list API and ~/.logs say, and fails on: an unconfirmed create,
 *                                                a job of this session that is still armed/listed, a job that runs but is not listed (HIDDEN),
 *                                                a listed job nobody created, a deleted job whose log still advances, and the permanent job missing
 *                                                or changed.
 *   verify-gone <ledger> <uid> <logs-before.txt> <logs-after.txt>
 *                                                the only thing that can mark a ledger job `gone`: its log file is absent from a later listing, or
 *                                                frozen while the listing moved on. Take the two listings at least two minutes apart.
 *   resolve <ledger> <uid-or-id> "<note>"        closes an unconfirmed entry after a person checked ~/.logs.
 *
 * logs.txt is the output of a read-only probe cron:  ls -la --time-style=full-iso /home/u951246149/.logs
 * (the raw text or the {"output": "..."} wrapper the API puts around it are both accepted).
 */

// ------------------------------------------------------------------------------------------------ pure functions

/** Strips the {"output": "..."} wrapper the hosting API puts around a cron job's captured output. */
function cg_unwrap_output(string $text): string
{
    $trimmed = ltrim($text);
    if ($trimmed !== '' && $trimmed[0] === '{') {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && is_string($decoded['output'] ?? null)) {
            return $decoded['output'];
        }
    }

    return $text;
}

/**
 * `ls -la --time-style=full-iso ~/.logs` → ['<uid>' => ['mtime' => int (UTC epoch), 'size' => int]]. Only `cronjob_<uid>` files count.
 *
 * @return array<string, array{mtime: int, size: int}>
 */
function cg_parse_log_listing(string $text): array
{
    $rows = [];
    foreach (preg_split('/\R/', cg_unwrap_output($text)) ?: [] as $line) {
        if (! preg_match('/^\S+\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})(?:\.\d+)?\s+([+-]\d{4})\s+cronjob_([A-Za-z0-9]+)\s*$/', $line, $m)) {
            continue;
        }
        $mtime = strtotime("{$m[2]} {$m[3]} {$m[4]}");
        if ($mtime !== false) {
            $rows[$m[5]] = ['mtime' => $mtime, 'size' => (int) $m[1]];
        }
    }

    return $rows;
}

/**
 * The `hosting_cron-jobs_list` response → ['<uid>' => ['time' => string, 'command' => string]].
 *
 * @return array<string, array{time: string, command: string}>
 */
function cg_parse_job_list(string $json): array
{
    $data = json_decode($json, true);
    if (is_array($data) && is_array($data['data'] ?? null)) {
        $data = $data['data'];
    }
    $jobs = [];
    foreach (is_array($data) ? $data : [] as $job) {
        if (is_array($job) && is_string($job['uid'] ?? null) && $job['uid'] !== '') {
            $jobs[$job['uid']] = ['time' => (string) ($job['time'] ?? ''), 'command' => (string) ($job['command'] ?? '')];
        }
    }

    return $jobs;
}

/**
 * Is a `hosting_cron-jobs_create` response a CONFIRMED job? Every field must be there, the schedule must be the requested one and the
 * command must carry the session id. Returns ['ok' => bool, 'uid' => ?string, 'problems' => string[]].
 *
 * @return array{ok: bool, uid: ?string, problems: list<string>}
 */
function cg_check_create_response(string $json, string $schedule, string $id): array
{
    $record = json_decode($json, true);
    if (! is_array($record)) {
        return ['ok' => false, 'uid' => null, 'problems' => ['the response is not a JSON object']];
    }
    $problems = [];
    $uid = $record['uid'] ?? '';
    if (! is_string($uid) || ! preg_match('/^[A-Za-z0-9]{6,32}$/', $uid)) {
        $problems[] = 'no job id (uid) came back';
    }
    foreach (['username', 'time', 'command'] as $field) {
        if (! is_string($record[$field] ?? null) || trim($record[$field]) === '') {
            $problems[] = "the record's {$field} is empty";
        }
    }
    if (is_string($record['time'] ?? null) && trim($record['time']) !== '' && trim($record['time']) !== trim($schedule)) {
        $problems[] = 'the schedule that came back is not the one requested';
    }
    if (is_string($record['command'] ?? null) && trim($record['command']) !== '' && ! str_contains($record['command'], $id)) {
        $problems[] = "the command does not carry this session's id ({$id}), so the job could not be told apart from another";
    }

    return ['ok' => $problems === [], 'uid' => is_string($uid) && $uid !== '' ? $uid : null, 'problems' => $problems];
}

/**
 * Everything the ledger, the list API and ~/.logs say, side by side.
 *
 * @param  list<array<string, mixed>>  $ledgerJobs
 * @param  array<string, array{time: string, command: string}>  $listed
 * @param  array<string, array{mtime: int, size: int}>  $logs
 * @param  array<string, array{time: string, command: string, what?: string}>  $permanent
 * @param  array<string, string>  $knownHidden
 * @return list<array{level: string, code: string, uid: ?string, message: string}>
 */
function cg_reconcile(array $ledgerJobs, array $listed, array $logs, array $permanent = [], array $knownHidden = [], int $activeWindow = 150): array
{
    $findings = [];
    $add = function (string $level, string $code, ?string $uid, string $message) use (&$findings): void {
        $findings[] = ['level' => $level, 'code' => $code, 'uid' => $uid, 'message' => $message];
    };

    if ($logs === []) {
        $add('error', 'LOG-LISTING-EMPTY', null, 'the ~/.logs listing holds no cronjob_<uid> file — the probe did not run or its output was cut; nothing can be concluded');

        return $findings;
    }
    $reference = max(array_column($logs, 'mtime'));
    $active = fn (string $uid): bool => isset($logs[$uid]) && ($reference - $logs[$uid]['mtime']) <= $activeWindow;

    foreach ($permanent as $uid => $expected) {
        if (! isset($listed[$uid])) {
            $add('error', 'PERMANENT-MISSING', $uid, 'the permanent job '.($expected['what'] ?? '').' is not in the list'.($active($uid) ? ' (its log is still advancing, so it runs but is unlisted)' : ''));
        } elseif (trim($listed[$uid]['time']) !== trim($expected['time']) || trim($listed[$uid]['command']) !== trim($expected['command'])) {
            $add('error', 'PERMANENT-CHANGED', $uid, 'the permanent job no longer has the expected schedule/command');
        }
    }

    $ledgerUids = [];
    foreach ($ledgerJobs as $job) {
        $uid = is_string($job['uid'] ?? null) ? $job['uid'] : null;
        $status = (string) ($job['status'] ?? '');
        if ($uid !== null) {
            $ledgerUids[$uid] = true;
        }
        if ($status === 'unconfirmed' && empty($job['resolved'])) {
            $add('error', 'CREATE-UNCONFIRMED', null, 'a create for session '.($job['id'] ?? '?').' came back without a confirmed job id — a job may exist that the API can neither list nor delete; look for it in ~/.logs and resolve it by hand');
        } elseif ($status === 'armed' && $uid !== null) {
            $add('error', isset($listed[$uid]) ? 'STILL-LISTED' : 'DELETE-NOT-VERIFIED', $uid, isset($listed[$uid])
                ? 'a job of this session is still listed — delete it, then run verify-gone'
                : 'no longer listed, but a delete is not proof: run verify-gone with two listings of ~/.logs taken two minutes apart');
        } elseif ($status === 'gone' && $uid !== null && $active($uid)) {
            $add('error', 'DELETE-LIED', $uid, 'marked gone, but its log is still being rewritten every minute');
        }
    }

    foreach ($listed as $uid => $job) {
        if (! isset($permanent[$uid]) && ! isset($ledgerUids[$uid])) {
            $add('error', 'UNKNOWN-LISTED', $uid, 'a listed job that no ledger entry accounts for: '.$job['time'].' '.$job['command']);
        }
    }

    foreach ($logs as $uid => $log) {
        if (isset($listed[$uid]) || isset($permanent[$uid]) || isset($ledgerUids[$uid])) {
            continue;
        }
        if ($active($uid)) {
            isset($knownHidden[$uid])
                ? $add('warning', 'HIDDEN-KNOWN', $uid, 'runs but is not listed — documented: '.$knownHidden[$uid])
                : $add('error', 'HIDDEN-ACTIVE', $uid, 'runs (its log is rewritten every minute) but is not in the list — a job the API cannot show or delete');
        } else {
            $add('info', 'STALE-LOG', $uid, 'a log with no listed job and no recent run (a finished one-shot, or a yearly one that is still scheduled)'.(isset($knownHidden[$uid]) ? ' — documented: '.$knownHidden[$uid] : ''));
        }
    }

    return $findings;
}

/**
 * Did deleting a job really stop it? Two listings of ~/.logs, taken at least two minutes apart.
 *
 * @param  array<string, array{mtime: int, size: int}>  $before
 * @param  array<string, array{mtime: int, size: int}>  $after
 * @return array{ok: bool, verdict: string, message: string}
 */
function cg_verify_gone(string $uid, array $before, array $after, int $activeWindow = 150): array
{
    if (! isset($before[$uid])) {
        return ['ok' => false, 'verdict' => 'UNKNOWN', 'message' => 'the uid is not in the earlier listing — nothing to compare'];
    }
    if ($before === [] || $after === []) {
        return ['ok' => false, 'verdict' => 'UNDECIDED', 'message' => 'a listing is empty'];
    }
    if (max(array_column($after, 'mtime')) - max(array_column($before, 'mtime')) < 100) {
        return ['ok' => false, 'verdict' => 'UNDECIDED', 'message' => 'the two listings are less than two minutes apart'];
    }
    if (! isset($after[$uid])) {
        return ['ok' => true, 'verdict' => 'GONE', 'message' => 'its log file is gone from the later listing'];
    }
    if ($after[$uid]['mtime'] > $before[$uid]['mtime']) {
        return ['ok' => false, 'verdict' => 'STILL-RUNNING', 'message' => 'its log was rewritten after the earlier listing — the job still runs'];
    }
    if (max(array_column($after, 'mtime')) - $after[$uid]['mtime'] > $activeWindow) {
        return ['ok' => true, 'verdict' => 'STOPPED', 'message' => 'its log is frozen while the listing moved on'];
    }

    return ['ok' => false, 'verdict' => 'UNDECIDED', 'message' => 'its log is unchanged but too recent to tell — list again later'];
}

// ------------------------------------------------------------------------------------------------ CLI

/** @return array{jobs: list<array<string, mixed>>} */
function cg_ledger_load(string $path): array
{
    if (! is_file($path)) {
        return ['jobs' => []];
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) && is_array($data['jobs'] ?? null) ? ['jobs' => array_values($data['jobs'])] : ['jobs' => []];
}

function cg_ledger_save(string $path, array $ledger): void
{
    file_put_contents($path, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function cg_main(array $argv): int
{
    $command = $argv[1] ?? '';
    $read = static function (string $path): string {
        $text = @file_get_contents($path);
        if ($text === false) {
            fwrite(STDERR, "cron-guard: cannot read {$path}\n");
            exit(64);
        }

        return $text;
    };

    switch ($command) {
        case 'new-session':
            $id = bin2hex(random_bytes(4));
            echo json_encode(['id' => $id, 'kit_file' => "_qa_{$id}.php", 'command_tag' => "env QA_ID={$id}"], JSON_UNESCAPED_SLASHES), "\n";

            return 0;

        case 'confirm-create':
            [$ledgerPath, $responsePath, $schedule, $id] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''];
            if ($ledgerPath === '' || $responsePath === '' || $schedule === '' || $id === '') {
                fwrite(STDERR, "usage: cron-guard.php confirm-create <ledger.json> <create-response.json> \"<schedule>\" <session id>\n");

                return 64;
            }
            $responseText = $read($responsePath);
            $check = cg_check_create_response($responseText, $schedule, $id);
            $ledger = cg_ledger_load($ledgerPath);
            $response = json_decode($responseText, true);
            if ($check['ok']) {
                $ledger['jobs'][] = ['id' => $id, 'uid' => $check['uid'], 'status' => 'armed', 'schedule' => $schedule,
                    'command' => (string) $response['command'], 'created_at' => gmdate('c')];
                cg_ledger_save($ledgerPath, $ledger);
                echo "ARMED {$check['uid']} — delete it when done, then run verify-gone\n";

                return 0;
            }
            $ledger['jobs'][] = ['id' => $id, 'uid' => $check['uid'], 'status' => 'unconfirmed', 'schedule' => $schedule,
                'problems' => $check['problems'], 'created_at' => gmdate('c')];
            cg_ledger_save($ledgerPath, $ledger);
            echo "CREATE FAILED — ".implode('; ', $check['problems'])."\n";
            echo "Treat the job as NOT armed and as possibly EXISTING: the API may be unable to list or delete it. Do not continue the session on the assumption either way;\n";
            echo "list ~/.logs with a read-only probe and run reconcile.\n";

            return 3;

        case 'reconcile':
            [$ledgerPath, $listPath, $logsPath] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
            if ($ledgerPath === '' || $listPath === '' || $logsPath === '') {
                fwrite(STDERR, "usage: cron-guard.php reconcile <ledger.json> <list.json> <logs.txt> [<known.json>]\n");

                return 64;
            }
            $knownPath = $argv[5] ?? __DIR__.'/cron-known.json';
            $known = is_file($knownPath) ? (json_decode((string) file_get_contents($knownPath), true) ?: []) : [];
            $findings = cg_reconcile(cg_ledger_load($ledgerPath)['jobs'], cg_parse_job_list($read($listPath)), cg_parse_log_listing($read($logsPath)),
                is_array($known['permanent'] ?? null) ? $known['permanent'] : [], is_array($known['known_hidden'] ?? null) ? $known['known_hidden'] : []);
            $errors = 0;
            foreach ($findings as $f) {
                $errors += $f['level'] === 'error' ? 1 : 0;
                printf("%-8s %-20s %-12s %s\n", strtoupper($f['level']), $f['code'], $f['uid'] ?? '-', $f['message']);
            }
            echo $errors === 0 ? "RECONCILED — no unexplained job, nothing of this session left armed\n" : "NOT CLEAN — {$errors} problem(s)\n";

            return $errors === 0 ? 0 : 1;

        case 'verify-gone':
            [$ledgerPath, $uid, $beforePath, $afterPath] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''];
            if ($ledgerPath === '' || $uid === '' || $beforePath === '' || $afterPath === '') {
                fwrite(STDERR, "usage: cron-guard.php verify-gone <ledger.json> <uid> <logs-before.txt> <logs-after.txt>\n");

                return 64;
            }
            $verdict = cg_verify_gone($uid, cg_parse_log_listing($read($beforePath)), cg_parse_log_listing($read($afterPath)));
            echo "{$verdict['verdict']} — {$verdict['message']}\n";
            if ($verdict['ok']) {
                $ledger = cg_ledger_load($ledgerPath);
                foreach ($ledger['jobs'] as &$job) {
                    if (($job['uid'] ?? null) === $uid) {
                        $job['status'] = 'gone';
                        $job['verified_at'] = gmdate('c');
                        $job['verdict'] = $verdict['verdict'];
                    }
                }
                unset($job);
                cg_ledger_save($ledgerPath, $ledger);
            }

            return $verdict['ok'] ? 0 : 1;

        case 'resolve':
            [$ledgerPath, $key, $note] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
            if ($ledgerPath === '' || $key === '' || $note === '') {
                fwrite(STDERR, "usage: cron-guard.php resolve <ledger.json> <uid-or-session-id> \"<what you found in ~/.logs>\"\n");

                return 64;
            }
            $ledger = cg_ledger_load($ledgerPath);
            $closed = 0;
            foreach ($ledger['jobs'] as &$job) {
                $open = ($job['status'] ?? '') === 'unconfirmed' && empty($job['resolved']);
                if ($open && (($job['uid'] ?? null) === $key || ($job['id'] ?? null) === $key)) {
                    $job['resolved'] = $note;
                    $closed++;
                }
            }
            unset($job);
            cg_ledger_save($ledgerPath, $ledger);
            echo $closed > 0 ? "resolved {$closed} unconfirmed entr".($closed === 1 ? 'y' : 'ies')."\n" : "nothing to resolve for {$key}\n";

            return $closed > 0 ? 0 : 1;
    }

    fwrite(STDERR, "usage: cron-guard.php new-session | confirm-create | reconcile | verify-gone | resolve   (see the header of this file)\n");

    return 64;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(cg_main($argv));
}
