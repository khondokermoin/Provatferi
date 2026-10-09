<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * deploy/qa/cron-guard.php — the guard that came out of the 2026-10-09 orphan-cron clean-up.
 *
 * The fixtures are the real shapes seen that day: the `ls -la --time-style=full-iso ~/.logs` of the account (a minutely orphan
 * `tHqWoa4E6h` and a read-only probe `4TLmOktkSo` that the cron-jobs list never showed, three stale one-shot logs, the permanent
 * dues job, a probe of the session itself and an unrelated error log) and the blank create response that started it all.
 */
class CronGuardTest extends TestCase
{
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('deploy/qa/cron-guard.php');
    }

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

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/pf-cron-guard-'.bin2hex(random_bytes(5));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** The probe's own log is the newest file: that is the "now" of the listing. */
    private function logsListing(array $override = []): string
    {
        $rows = array_merge([
            'cronjob_4EucqENwqk' => [555, '2026-10-05 12:49:02.392073217'],
            'cronjob_4TLmOktkSo' => [1328, '2026-10-09 11:10:02.344691713'],
            'cronjob_7jx3Ge1Vz2' => [0, '2026-10-09 11:10:02.303944224'],
            'cronjob_DWDyIEjfTM' => [156, '2026-10-08 18:05:04.148578758'],
            'cronjob_qFGqvw6Wwy' => [682, '2026-09-21 09:11:03.252564524'],
            'cronjob_Tgf0Y6VWdW' => [2433, '2026-09-08 11:53:03.062813996'],
            'cronjob_tHqWoa4E6h' => [0, '2026-10-09 11:10:01.795942085'],
        ], $override);
        $out = "total 2100\ndrwxr-xr-x  2 u951246149 o1008535165    4096 2026-10-09 10:59:55.233388143 +0000 .\n";
        foreach ($rows as $name => $row) {
            if ($row !== null) {
                $out .= sprintf("-rw-r--r--  1 u951246149 o1008535165 %7d %s +0000 %s\n", $row[0], $row[1], $name);
            }
        }

        return $out."-rw-r--r--  1 u951246149 o1008535165  604954 2026-10-09 11:09:59.178931064 +0000 error_log_nexhomebd_com\n";
    }

    private function permanent(): array
    {
        return ['DWDyIEjfTM' => ['time' => '5 18 * * *', 'command' => 'php /home/u951246149/domains/provatferi.org/laravel-admin/artisan membership:generate-dues', 'what' => 'dues']];
    }

    private function dues(): array
    {
        return ['DWDyIEjfTM' => ['time' => '5 18 * * *', 'command' => 'php /home/u951246149/domains/provatferi.org/laravel-admin/artisan membership:generate-dues']];
    }

    private function codes(array $findings, ?string $level = null): array
    {
        return array_values(array_map(fn ($f) => $f['code'].':'.($f['uid'] ?? '-'),
            array_filter($findings, fn ($f) => $level === null || $f['level'] === $level)));
    }

    public function test_it_reads_the_logs_directory_and_ignores_every_other_file(): void
    {
        $logs = cg_parse_log_listing($this->logsListing());

        $this->assertSame(['4EucqENwqk', '4TLmOktkSo', '7jx3Ge1Vz2', 'DWDyIEjfTM', 'qFGqvw6Wwy', 'Tgf0Y6VWdW', 'tHqWoa4E6h'], array_keys($logs));
        $this->assertSame(555, $logs['4EucqENwqk']['size']);
        $this->assertSame(strtotime('2026-10-09 11:10:01 +0000'), $logs['tHqWoa4E6h']['mtime']);

        // The API wraps a cron job's captured output in {"output": "..."}; that form is read the same way.
        $this->assertSame($logs, cg_parse_log_listing(json_encode(['output' => $this->logsListing()])));
    }

    public function test_it_reads_the_job_list(): void
    {
        $json = json_encode([['uid' => 'DWDyIEjfTM', 'username' => 'u951246149', 'time' => '5 18 * * *', 'command' => 'php artisan x'], ['uid' => '', 'time' => 'x', 'command' => 'y']]);

        $this->assertSame(['DWDyIEjfTM' => ['time' => '5 18 * * *', 'command' => 'php artisan x']], cg_parse_job_list($json));
        $this->assertSame([], cg_parse_job_list('[]'));
        $this->assertSame([], cg_parse_job_list('not json'));
    }

    public function test_the_blank_create_response_that_made_the_orphan_is_a_failed_create(): void
    {
        $blank = json_encode(['uid' => '', 'username' => '', 'time' => '', 'command' => '']);

        $check = cg_check_create_response($blank, '* * * * *', 'a1b2c3d4');

        $this->assertFalse($check['ok']);
        $this->assertNull($check['uid']);
        $this->assertContains('no job id (uid) came back', $check['problems']);
        $this->assertContains("the record's username is empty", $check['problems']);
        $this->assertContains("the record's command is empty", $check['problems']);
        $this->assertFalse(cg_check_create_response('', '* * * * *', 'a1b2c3d4')['ok']);
    }

    public function test_a_create_is_confirmed_only_when_every_field_the_schedule_and_the_session_id_are_right(): void
    {
        $good = ['uid' => 'ZE3gK3jiMc', 'username' => 'u951246149', 'time' => '* * * * *', 'command' => 'php /home/u951246149/domains/provatferi.org/public_html/admin/_qa_a1b2c3d4.php snapshot'];

        $this->assertTrue(cg_check_create_response(json_encode($good), '* * * * *', 'a1b2c3d4')['ok']);
        $this->assertSame('ZE3gK3jiMc', cg_check_create_response(json_encode($good), '* * * * *', 'a1b2c3d4')['uid']);

        $this->assertFalse(cg_check_create_response(json_encode($good), '*/5 * * * *', 'a1b2c3d4')['ok'], 'another schedule came back');
        $this->assertFalse(cg_check_create_response(json_encode($good), '* * * * *', 'ffffffff')['ok'], 'a command without the session id');
        $this->assertFalse(cg_check_create_response(json_encode([...$good, 'uid' => 'x']), '* * * * *', 'a1b2c3d4')['ok'], 'a uid that cannot be a job id');
    }

    public function test_reconcile_sees_the_orphan_that_the_list_cannot_show(): void
    {
        // The probe that took the listing is itself a job (listed in a real session); it is left out here to keep the picture to the account's own jobs.
        $logs = cg_parse_log_listing($this->logsListing(['cronjob_7jx3Ge1Vz2' => null]));

        $findings = cg_reconcile([], $this->dues(), $logs, $this->permanent());

        $this->assertSame(['HIDDEN-ACTIVE:4TLmOktkSo', 'HIDDEN-ACTIVE:tHqWoa4E6h'], $this->codes($findings, 'error'));
        $this->assertSame(['STALE-LOG:4EucqENwqk', 'STALE-LOG:qFGqvw6Wwy', 'STALE-LOG:Tgf0Y6VWdW'], $this->codes($findings, 'info'));
        $this->assertNotContains('HIDDEN-ACTIVE:DWDyIEjfTM', $this->codes($findings), 'the permanent job is never "hidden"');
    }

    public function test_known_hidden_jobs_are_warnings_not_failures_but_stay_visible(): void
    {
        $logs = cg_parse_log_listing($this->logsListing(['cronjob_7jx3Ge1Vz2' => null]));
        $known = ['tHqWoa4E6h' => 'the orphan', '4TLmOktkSo' => 'a probe', '4EucqENwqk' => 'old'];

        $findings = cg_reconcile([], $this->dues(), $logs, $this->permanent(), $known);

        $this->assertSame([], $this->codes($findings, 'error'), json_encode($findings));
        $this->assertSame(['HIDDEN-KNOWN:4TLmOktkSo', 'HIDDEN-KNOWN:tHqWoa4E6h'], $this->codes($findings, 'warning'));
        $this->assertStringContainsString('documented: old', implode(' ', array_column(array_filter($findings, fn ($f) => $f['uid'] === '4EucqENwqk'), 'message')));
    }

    public function test_the_permanent_dues_job_must_be_listed_and_unchanged(): void
    {
        $logs = cg_parse_log_listing($this->logsListing(['cronjob_7jx3Ge1Vz2' => null]));
        $known = ['tHqWoa4E6h' => 'x', '4TLmOktkSo' => 'x'];

        $missing = cg_reconcile([], [], $logs, $this->permanent(), $known);
        $changed = cg_reconcile([], ['DWDyIEjfTM' => ['time' => '* * * * *', 'command' => 'php artisan membership:generate-dues']], $logs, $this->permanent(), $known);
        $fine = cg_reconcile([], $this->dues(), $logs, $this->permanent(), $known);

        $this->assertSame(['PERMANENT-MISSING:DWDyIEjfTM'], $this->codes($missing, 'error'));
        $this->assertSame(['PERMANENT-CHANGED:DWDyIEjfTM'], $this->codes($changed, 'error'));
        $this->assertSame([], $this->codes($fine, 'error'));
    }

    public function test_a_session_is_not_clean_while_a_job_is_armed_unconfirmed_or_unaccounted_for(): void
    {
        $logs = cg_parse_log_listing($this->logsListing());
        $known = ['tHqWoa4E6h' => 'x', '4TLmOktkSo' => 'x'];
        $listed = $this->dues() + [
            '7jx3Ge1Vz2' => ['time' => '* * * * *', 'command' => 'ls'],          // armed by this session, still listed
            'ZZZZZZZZZZ' => ['time' => '* * * * *', 'command' => 'who made me'], // nobody's
        ];
        $ledger = [
            ['id' => 'a1b2c3d4', 'uid' => '7jx3Ge1Vz2', 'status' => 'armed'],
            ['id' => 'a1b2c3d4', 'uid' => null, 'status' => 'unconfirmed'],
            ['id' => 'a1b2c3d4', 'uid' => 'Qwerty1234', 'status' => 'armed'], // not listed any more, delete never verified
        ];

        $findings = cg_reconcile($ledger, $listed, $logs, $this->permanent(), $known);

        $this->assertEqualsCanonicalizing(
            ['STILL-LISTED:7jx3Ge1Vz2', 'CREATE-UNCONFIRMED:-', 'DELETE-NOT-VERIFIED:Qwerty1234', 'UNKNOWN-LISTED:ZZZZZZZZZZ'],
            $this->codes($findings, 'error'),
        );

        // A person who looked at ~/.logs can close the unconfirmed entry; nothing else closes it.
        $ledger[1]['resolved'] = 'no new cronjob_ log appeared';
        $this->assertNotContains('CREATE-UNCONFIRMED:-', $this->codes(cg_reconcile($ledger, $listed, $logs, $this->permanent(), $known)));
    }

    public function test_a_job_marked_gone_whose_log_still_advances_is_caught_lying(): void
    {
        $logs = cg_parse_log_listing($this->logsListing(['cronjob_7jx3Ge1Vz2' => null]));

        $findings = cg_reconcile([['id' => 'x', 'uid' => 'tHqWoa4E6h', 'status' => 'gone']], $this->dues(), $logs, $this->permanent(), ['4TLmOktkSo' => 'x']);

        $this->assertSame(['DELETE-LIED:tHqWoa4E6h'], $this->codes($findings, 'error'));
    }

    public function test_an_empty_or_cut_logs_listing_proves_nothing(): void
    {
        $this->assertSame(['LOG-LISTING-EMPTY:-'], $this->codes(cg_reconcile([], $this->dues(), [], $this->permanent())));
    }

    public function test_verify_gone_distinguishes_gone_stopped_still_running_and_too_close_to_tell(): void
    {
        $before = cg_parse_log_listing($this->logsListing());
        $laterRows = fn (array $override) => cg_parse_log_listing($this->logsListing($override + [
            'cronjob_7jx3Ge1Vz2' => [0, '2026-10-09 11:13:30.000000000'],
            'cronjob_4TLmOktkSo' => [1328, '2026-10-09 11:13:31.000000000'],
        ]));

        // The listing is two minutes newer and the file is gone: deleted for real.
        $this->assertSame('GONE', cg_verify_gone('tHqWoa4E6h', $before, $laterRows(['cronjob_tHqWoa4E6h' => null]))['verdict']);
        // Still being rewritten: the 2026-10-09 orphan, twice "deleted" through the API.
        $still = cg_verify_gone('tHqWoa4E6h', $before, $laterRows(['cronjob_tHqWoa4E6h' => [0, '2026-10-09 11:13:30.500000000']]));
        $this->assertSame('STILL-RUNNING', $still['verdict']);
        $this->assertFalse($still['ok']);
        // Present but frozen while the listing moved on: stopped.
        $this->assertSame('STOPPED', cg_verify_gone('tHqWoa4E6h', $before, $laterRows([]))['verdict']);
        // Listings under two minutes apart prove nothing.
        $this->assertSame('UNDECIDED', cg_verify_gone('tHqWoa4E6h', $before, $before)['verdict']);
        $this->assertSame('UNKNOWN', cg_verify_gone('nope', $before, $laterRows([]))['verdict']);
    }

    public function test_neutralize_gives_the_block_and_unblock_commands_and_never_touches_a_permanent_job(): void
    {
        $plan = cg_neutralize_commands('tHqWoa4E6h', $this->permanent());

        $this->assertTrue($plan['ok']);
        $this->assertSame('chmod 000 /tmp/cron_lock_tHqWoa4E6h', $plan['block']);
        $this->assertSame('chmod 644 /tmp/cron_lock_tHqWoa4E6h', $plan['unblock']);
        $this->assertStringContainsString('Permission denied', $plan['message']);

        $dues = cg_neutralize_commands('DWDyIEjfTM', $this->permanent());
        $this->assertFalse($dues['ok'], 'the dues cron can never be targeted, not even by a typo that happens to be its id');
        $this->assertNull($dues['block']);
        $this->assertStringContainsString('PERMANENT', $dues['message']);

        foreach (['', 'abc', '../etc/passwd', 'a b c d e f', 'tHqWoa4E6h; rm -rf /', 'tHqWoa4E6h/../x', str_repeat('a', 40)] as $bad) {
            $this->assertFalse(cg_neutralize_commands($bad, $this->permanent())['ok'], "not a job id: {$bad}");
        }
    }

    public function test_the_cli_neutralize_prints_the_plan_for_a_hidden_job_and_refuses_the_dues_cron(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/known.json', json_encode(['permanent' => $this->permanent(), 'known_hidden' => []]));
        $run = function (string $uid) use ($dir): array {
            $process = proc_open([PHP_BINARY, base_path('deploy/qa/cron-guard.php'), 'neutralize', $uid, $dir.'/known.json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $out, $err];
        };

        [$code, $out] = $run('tHqWoa4E6h');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('block:   chmod 000 /tmp/cron_lock_tHqWoa4E6h', $out);
        $this->assertStringContainsString('unblock: chmod 644 /tmp/cron_lock_tHqWoa4E6h', $out);

        [$code, $out, $err] = $run('DWDyIEjfTM');
        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('chmod', $out);
        $this->assertStringContainsString('PERMANENT job', $err);
    }

    public function test_the_cli_walks_a_session_from_a_blank_create_to_a_verified_clean_up(): void
    {
        $dir = $this->tempDir();
        $run = function (array $args) use ($dir): array {
            $process = proc_open([PHP_BINARY, base_path('deploy/qa/cron-guard.php'), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
            // stdout only: this machine's php.ini may print start-up warnings on stderr, and the guard's own words are all on stdout.
            $out = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $out];
        };
        $ledger = $dir.'/ledger.json';
        $known = $dir.'/known.json';
        file_put_contents($known, json_encode(['permanent' => $this->permanent(), 'known_hidden' => ['tHqWoa4E6h' => 'the orphan', '4TLmOktkSo' => 'a probe']]));

        [$code, $out] = $run(['new-session']);
        $this->assertSame(0, $code);
        // A development php.ini can print start-up warnings on stdout too; the guard's JSON is the line that starts with a brace.
        $session = json_decode(preg_match('/^\{.*\}$/m', $out, $found) ? $found[0] : '', true);
        $this->assertIsArray($session, $out);
        $id = $session['id'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $id);
        $this->assertSame("_qa_{$id}.php", $session['kit_file']);
        $this->assertMatchesRegularExpression('/^_qa_(?:[a-z]+_)?[0-9a-f]{8,}\.php$/', $session['kit_file'], 'the kit name the guard hands out is one the kits accept');

        // 1. a blank create: FAILED, exit 3, written down as unconfirmed.
        file_put_contents($dir.'/blank.json', json_encode(['uid' => '', 'username' => '', 'time' => '', 'command' => '']));
        [$code, $out] = $run(['confirm-create', $ledger, $dir.'/blank.json', '* * * * *', $id]);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('CREATE FAILED', $out);
        $this->assertSame('unconfirmed', json_decode(file_get_contents($ledger), true)['jobs'][0]['status']);

        // 2. a good create is armed.
        file_put_contents($dir.'/good.json', json_encode(['uid' => 'Qwerty1234', 'username' => 'u951246149', 'time' => '* * * * *', 'command' => "env QA_ID={$id} ls"]));
        [$code, $out] = $run(['confirm-create', $ledger, $dir.'/good.json', '* * * * *', $id]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('ARMED Qwerty1234', $out);

        // 3. reconcile fails while the create is unconfirmed and the job armed and listed.
        file_put_contents($dir.'/list.json', json_encode([
            ['uid' => 'DWDyIEjfTM', 'username' => 'u951246149', ...$this->dues()['DWDyIEjfTM']],
            ['uid' => 'Qwerty1234', 'username' => 'u951246149', 'time' => '* * * * *', 'command' => "env QA_ID={$id} ls"],
        ]));
        $before = $this->logsListing(['cronjob_Qwerty1234' => [0, '2026-10-09 11:10:02.000000000']]);
        file_put_contents($dir.'/logs-before.txt', $before);
        [$code, $out] = $run(['reconcile', $ledger, $dir.'/list.json', $dir.'/logs-before.txt', $known]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('CREATE-UNCONFIRMED', $out);
        $this->assertStringContainsString('STILL-LISTED', $out);

        // 4. delete, wait: the later listing has no log for the job. verify-gone marks it gone; the unconfirmed entry is resolved by hand.
        file_put_contents($dir.'/logs-after.txt', $this->logsListing([
            'cronjob_7jx3Ge1Vz2' => [0, '2026-10-09 11:13:02.000000000'], 'cronjob_4TLmOktkSo' => [1328, '2026-10-09 11:13:02.000000000'],
            'cronjob_tHqWoa4E6h' => [0, '2026-10-09 11:13:01.000000000'],
        ]));
        [$code, $out] = $run(['verify-gone', $ledger, 'Qwerty1234', $dir.'/logs-before.txt', $dir.'/logs-after.txt']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('GONE', $out);
        [$code] = $run(['resolve', $ledger, $id, 'no new cronjob_ log appeared in ~/.logs']);
        $this->assertSame(0, $code);

        file_put_contents($dir.'/list.json', json_encode([['uid' => 'DWDyIEjfTM', 'username' => 'u951246149', ...$this->dues()['DWDyIEjfTM']], ['uid' => '7jx3Ge1Vz2', 'username' => 'u951246149', 'time' => '* * * * *', 'command' => 'ls']]));
        [$code, $out] = $run(['reconcile', $ledger, $dir.'/list.json', $dir.'/logs-after.txt', $known]);
        $this->assertStringContainsString('HIDDEN-KNOWN', $out, 'the documented orphan stays on the report');
        $this->assertSame(1, $code, 'the probe listed by the API belongs to nobody in this ledger: '.$out);
        $this->assertStringContainsString('UNKNOWN-LISTED', $out);
    }
}
