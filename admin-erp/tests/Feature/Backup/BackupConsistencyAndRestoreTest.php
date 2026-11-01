<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupStore;
use App\Services\Backup\FileSetArchiver;
use App\Services\Backup\UploadConsistency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two things that are easy to get wrong and expensive to discover late
 * (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Consistency" and runbook B):
 *
 *   - a database backup and a file backup can each be perfect and still not fit together, so a row pointing at a file
 *     nobody kept has to be reported BEFORE the day someone needs it;
 *   - the restore commands run as the owner with the whole filesystem in reach, so their refusals matter more than
 *     their happy path. Restoring a private applicant document into a document root would publish it.
 */
class BackupConsistencyAndRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private string $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/pf-cons-'.bin2hex(random_bytes(5));
        $this->tree = $this->base.'/uploads';
        mkdir($this->tree.'/applications/cv', 0700, true);
        file_put_contents($this->tree.'/applications/cv/kept.pdf', 'a document that was kept');
    }

    protected function tearDown(): void
    {
        (new BackupStore($this->base))->removeDirectory($this->base);
        parent::tearDown();
    }

    public function test_it_reports_a_row_whose_file_is_missing_without_naming_the_file(): void
    {
        $posting = DB::table('job_postings')->insertGetId([
            'title' => 'Test posting', 'slug' => 'test-posting-'.bin2hex(random_bytes(3)),
            'description' => 'A fixture posting.', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $kept = DB::table('job_applications')->insertGetId([
            'job_posting_id' => $posting, 'application_no' => 'APP-TEST-'.bin2hex(random_bytes(3)),
            'applicant_name' => 'Kept', 'applicant_email' => 'kept@example.test', 'applicant_phone' => '01700000001',
            'cv_path' => 'applications/cv/kept.pdf', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lost = DB::table('job_applications')->insertGetId([
            'job_posting_id' => $posting, 'application_no' => 'APP-TEST-'.bin2hex(random_bytes(3)),
            'applicant_name' => 'Lost', 'applicant_email' => 'lost@example.test', 'applicant_phone' => '01700000002',
            'cv_path' => 'applications/cv/this-one-is-gone-'.bin2hex(random_bytes(4)).'.pdf', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $checker = new UploadConsistency([['table' => 'job_applications', 'column' => 'cv_path']], new FileSetArchiver);
        $report = $checker->check(['erp-private-uploads' => $this->tree]);

        $this->assertFalse($report['ok'], 'a reference with no file must not be reported as fine');
        $this->assertSame(2, $report['references']);
        $this->assertCount(1, $report['missing_live']);

        $problem = $report['missing_live'][0];
        $this->assertStringContainsString("job_applications.cv_path#{$lost}", $problem, 'the row must be findable');
        $this->assertStringNotContainsString('this-one-is-gone', $problem, 'the private filename must not be exposed');
        $this->assertStringNotContainsString('.pdf', $problem);

        // And the row whose file exists is not reported.
        $this->assertStringNotContainsString("#{$kept}", implode(' ', $report['missing_live']));
    }

    public function test_it_reports_a_file_that_is_in_the_live_tree_but_not_in_the_backup(): void
    {
        $posting = DB::table('job_postings')->insertGetId([
            'title' => 'Test posting', 'slug' => 'test-posting-'.bin2hex(random_bytes(3)),
            'description' => 'A fixture posting.', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('job_applications')->insert([
            'job_posting_id' => $posting, 'application_no' => 'APP-TEST-'.bin2hex(random_bytes(3)),
            'applicant_name' => 'Kept', 'applicant_email' => 'kept2@example.test', 'applicant_phone' => '01700000003',
            'cv_path' => 'applications/cv/kept.pdf', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A backup set whose index does NOT contain the file: taken before the upload, as a real set would be.
        $setDir = $this->base.'/set';
        mkdir($setDir, 0700, true);
        file_put_contents($setDir.'/files-erp-private-uploads.index.json', json_encode(['files' => []]));

        $checker = new UploadConsistency([['table' => 'job_applications', 'column' => 'cv_path']], new FileSetArchiver);
        $report = $checker->check(['erp-private-uploads' => $this->tree], $setDir);

        $this->assertFalse($report['ok']);
        $this->assertSame([], $report['missing_live'], 'the file is present live');
        $this->assertCount(1, $report['missing_backup'], 'but it is not in the backup, which is the point');
    }

    public function test_a_column_that_no_longer_exists_is_skipped_not_failed(): void
    {
        $checker = new UploadConsistency([
            ['table' => 'job_applications', 'column' => 'a_column_that_was_removed'],
            ['table' => 'a_table_that_never_existed', 'column' => 'x'],
        ], new FileSetArchiver);

        $report = $checker->check(['erp-private-uploads' => $this->tree]);

        $this->assertTrue($report['ok']);
        $this->assertSame(
            ['job_applications.a_column_that_was_removed', 'a_table_that_never_existed.x'],
            $report['tables_skipped']
        );
    }

    public function test_the_restore_command_refuses_an_unsafe_path_and_a_document_root_destination(): void
    {
        // A real set, so the command gets as far as validating its arguments.
        $store = new BackupStore($this->base.'/backups');
        $store->ensureRoot([]);
        $setDir = $store->setsDir().'/20261101T060000Z';
        mkdir($setDir, 0700, true);
        $archive = $setDir.'/files-erp-private-uploads.tar.gz';
        $index = $setDir.'/files-erp-private-uploads.index.json';
        $result = (new FileSetArchiver)->archive($this->tree, $archive, $index);
        $store->writeManifest($setDir, [
            'ok' => true,
            'id' => '20261101T060000Z',
            'artifacts' => [
                'files-erp-private-uploads.tar.gz' => ['kind' => 'files', 'bytes' => $result['archive_bytes'], 'sha256' => $result['archive_sha256'], 'entries' => $result['files']],
            ],
        ]);

        config(['backup.root' => $store->root(), 'backup.site_root' => $this->base.'/site']);

        // A traversal in --path.
        $this->artisan('backup:restore-file', [
            '--label' => 'erp-private-uploads',
            '--path' => '../../../etc/passwd',
            '--to' => $this->base.'/out',
        ])->assertExitCode(1);

        // A destination inside the document root, which would publish a private document.
        $this->artisan('backup:restore-file', [
            '--label' => 'erp-private-uploads',
            '--path' => 'applications/cv/kept.pdf',
            '--to' => public_path('leaked-restore'),
        ])->assertExitCode(1);
        $this->assertDirectoryDoesNotExist(public_path('leaked-restore'));

        // A label that is not a plain name.
        $this->artisan('backup:restore-file', [
            '--label' => '../../etc',
            '--path' => 'applications/cv/kept.pdf',
            '--to' => $this->base.'/out',
        ])->assertExitCode(1);

        // The happy path still works, and the restored bytes are checked against the index.
        $this->artisan('backup:restore-file', [
            '--label' => 'erp-private-uploads',
            '--path' => 'applications/cv/kept.pdf',
            '--to' => $this->base.'/out',
        ])->assertExitCode(0);
        $this->assertFileExists($this->base.'/out/kept.pdf');
        $this->assertSame(
            hash_file('sha256', $this->tree.'/applications/cv/kept.pdf'),
            hash_file('sha256', $this->base.'/out/kept.pdf')
        );
    }

    public function test_the_restore_command_refuses_a_set_that_does_not_verify(): void
    {
        $store = new BackupStore($this->base.'/backups2');
        $store->ensureRoot([]);
        $setDir = $store->setsDir().'/20261101T070000Z';
        mkdir($setDir, 0700, true);
        $archive = $setDir.'/files-erp-private-uploads.tar.gz';
        $index = $setDir.'/files-erp-private-uploads.index.json';
        (new FileSetArchiver)->archive($this->tree, $archive, $index);

        // A manifest whose hash does not match: the set is damaged, so nothing may be taken out of it.
        $store->writeManifest($setDir, [
            'ok' => true,
            'id' => '20261101T070000Z',
            'artifacts' => [
                'files-erp-private-uploads.tar.gz' => ['kind' => 'files', 'bytes' => (int) filesize($archive), 'sha256' => hash('sha256', 'not the real archive'), 'entries' => 1],
            ],
        ]);

        config(['backup.root' => $store->root(), 'backup.site_root' => $this->base.'/site']);

        $this->artisan('backup:restore-file', [
            '--label' => 'erp-private-uploads',
            '--path' => 'applications/cv/kept.pdf',
            '--to' => $this->base.'/out2',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist($this->base.'/out2/kept.pdf');
    }

    public function test_opening_a_configuration_bundle_without_a_key_is_refused(): void
    {
        $store = new BackupStore($this->base.'/backups3');
        $store->ensureRoot([]);
        $setDir = $store->setsDir().'/20261101T080000Z';
        mkdir($setDir, 0700, true);
        file_put_contents($setDir.'/config.vault', 'PFBKV1'.str_repeat('x', 60));
        $store->writeManifest($setDir, ['ok' => true, 'id' => '20261101T080000Z', 'artifacts' => []]);

        config(['backup.root' => $store->root(), 'backup.encryption_key' => null]);

        $this->artisan('backup:config-open', ['--to' => $this->base.'/cfg'])->assertExitCode(1);
        $this->assertDirectoryDoesNotExist($this->base.'/cfg');
    }
}
