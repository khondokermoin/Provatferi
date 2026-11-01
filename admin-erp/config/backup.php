<?php

/**
 * Backup and disaster recovery (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * Nothing here is a secret. The database credentials come from config/database.php at run time, the WordPress ones are
 * read from each site's own wp-config.php on the server, and the key that encrypts the configuration bundle is the one
 * value the owner holds — it is read from the environment and never written to a manifest, a log or a report.
 */
return [

    /*
     * The two roots everything else is derived from, so a layout change needs one override rather than six.
     * On production base_path() is `…/domains/provatferi.org/laravel-admin`, so:
     *   site_root     …/domains/provatferi.org          (one level up: the domain's own directory)
     *   account_root  /home/u951246149                  (three levels up: above `domains/`)
     */
    'site_root' => env('BACKUP_SITE_ROOT', dirname(base_path())),
    'account_root' => env('BACKUP_ACCOUNT_ROOT', dirname(base_path(), 3)),

    /*
     * Where backup sets are written. It MUST be outside every document root: nothing under public_html may ever be
     * reachable over HTTP, and nothing in a release tree survives a deploy. The default sits beside `domains/`, which
     * both the deploy pipeline and the web server ignore entirely.
     */
    'root' => env('BACKUP_ROOT', dirname(base_path(), 3).'/backups'),

    /*
     * The passphrase that encrypts the configuration bundle (the one artifact that contains secrets). Absent means the
     * bundle is SKIPPED, never written in the clear. The owner generates it, stores it off-host, and pastes it into the
     * server's .env — see the documentation. It must never be committed, printed or mailed.
     */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    /*
     * Grandfather-father-son retention, applied to the sets that exist rather than by making extra copies: the newest
     * `daily` sets, plus the newest set of each of the last `weekly` ISO weeks and of each of the last `monthly`
     * calendar months. Pruning is fail-safe (App\Services\Backup\BackupStore::prune).
     */
    'retention' => [
        'daily' => (int) env('BACKUP_KEEP_DAILY', 7),
        'weekly' => (int) env('BACKUP_KEEP_WEEKLY', 4),
        'monthly' => (int) env('BACKUP_KEEP_MONTHLY', 3),
    ],

    /* A backup older than this is reported as STALE by `backup:status`, which then exits non-zero. */
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 36),

    /* When the nightly cron is expected, for `backup:status` to report the next expected run. Asia/Dhaka. */
    'schedule' => [
        'timezone' => 'Asia/Dhaka',
        'hour' => (int) env('BACKUP_HOUR', 3),
        'minute' => (int) env('BACKUP_MINUTE', 30),
    ],

    /*
     * `mysqldump` is the only external program this runs. The password is passed in a 0600 defaults file, never on the
     * command line, so it cannot appear in `ps`, in a log or in cron output.
     */
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysqldump_timeout' => (int) env('BACKUP_MYSQLDUMP_TIMEOUT', 900),

    /*
     * A lock older than this is treated as abandoned (a killed process leaves the file behind). Long enough that a
     * genuinely slow run is never cut off.
     */
    'lock_stale_after_seconds' => (int) env('BACKUP_LOCK_STALE_SECONDS', 7200),

    /*
     * The databases to dump. The ERP's credentials are taken from the live connection. A WordPress database is included
     * only when its wp-config.php is readable, and its credentials are parsed from that file and kept in memory.
     */
    'databases' => [
        'erp' => ['driver' => 'laravel', 'connection' => env('DB_CONNECTION', 'mysql')],
        'cms' => ['driver' => 'wp-config', 'path' => env('BACKUP_WP_CMS_CONFIG', dirname(base_path()).'/public_html/cms/wp-config.php')],
        'archive' => ['driver' => 'wp-config', 'path' => env('BACKUP_WP_ARCHIVE_CONFIG', dirname(base_path()).'/public_html/archive/wp-config.php')],
    ],

    /*
     * The file trees to copy. Class B (private uploads) and C (public media) only: application code is reproducible
     * from Git and is deliberately NOT here, and neither is anything generated.
     */
    'file_sets' => [
        'erp-private-uploads' => storage_path('app/private/uploads'),
        'erp-public-uploads' => public_path('storage'),
        'cms-uploads' => dirname(base_path()).'/public_html/cms/wp-content/uploads',
        'archive-uploads' => dirname(base_path()).'/public_html/archive/wp-content/uploads',
    ],

    /*
     * Never copied, whatever tree they appear in: reproducible, regenerated, or disposable. `_deploy_sentinel` is the
     * deploy tooling's own marker; restoring it would be meaningless.
     */
    'exclude' => [
        'node_modules', 'vendor', '.git', '.cache', 'cache', '_deploy_sentinel',
        '.DS_Store', 'Thumbs.db', '.well-known',
    ],

    /*
     * The configuration bundle (class D). Each entry is copied verbatim into the ENCRYPTED bundle and never anywhere
     * else. A path that does not exist is recorded as absent rather than failing the run.
     */
    'config_files' => [
        'laravel.env' => base_path('.env'),
        'admin-front-controller.php' => public_path('index.php'),
        'admin-docroot.htaccess' => public_path('.htaccess'),
        'apex-docroot.htaccess' => dirname(base_path()).'/public_html/.htaccess',
        'cms-wp-config.php' => dirname(base_path()).'/public_html/cms/wp-config.php',
        'archive-wp-config.php' => dirname(base_path()).'/public_html/archive/wp-config.php',
        'cron-known.json' => base_path('deploy/qa/cron-known.json'),
    ],

    /*
     * The columns that hold a path to a stored file. Listed explicitly rather than discovered by name, so that adding
     * a column that stores an upload is a deliberate decision to protect it (App\Services\Backup\UploadConsistency).
     * Verified against the live schema on every check: a column that no longer exists is reported as skipped, not as
     * a failure.
     */
    'consistency' => [
        ['table' => 'job_applications', 'column' => 'cv_path'],
        ['table' => 'job_applications', 'column' => 'photo_path'],
        ['table' => 'committee_submissions', 'column' => 'photo_path'],
        ['table' => 'committee_submissions', 'column' => 'photo_approved_path'],
        ['table' => 'members', 'column' => 'photo_path'],
        ['table' => 'public_member_profile_versions', 'column' => 'photo_path'],
        ['table' => 'public_member_profile_versions', 'column' => 'photo_approved_path'],
        ['table' => 'leadership_profiles', 'column' => 'photo_path'],
        ['table' => 'leadership_profiles', 'column' => 'photo_sm_path'],
        ['table' => 'notices', 'column' => 'attachment_path'],
        ['table' => 'notices', 'column' => 'cover_image_path'],
        ['table' => 'notices', 'column' => 'share_image_path'],
        ['table' => 'activities', 'column' => 'hero_image_path'],
        ['table' => 'homepage_carousel_slides', 'column' => 'image_path'],
        ['table' => 'job_postings', 'column' => 'share_image_path'],
    ],

    /*
     * Where the alert goes when a run fails. The internal staff mailbox by default (docs/EMAIL_ARCHITECTURE.md): the
     * message carries From no-reply@ and Reply-To security@, and never any backup content.
     */
    'alert_to' => env('BACKUP_ALERT_TO'),

    /* Off-host copy (layer 2). Disabled until the owner supplies a destination — see the documentation. */
    'offhost' => [
        'driver' => env('BACKUP_OFFHOST_DRIVER'),   // null = not configured
        'target' => env('BACKUP_OFFHOST_TARGET'),
    ],
];
