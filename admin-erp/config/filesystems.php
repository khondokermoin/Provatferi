<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Public (approved-derivative) uploads. The root is NOT
        // storage_path('app/public') on this host, and that is deliberate:
        // Laravel's stock layout needs `storage:link` to make that directory
        // web-reachable, and symlink() is in this host's php.ini
        // disable_functions (confirmed empirically — see
        // deploy/remote/release-manager.php's own header, which had to use
        // copyRecursive for the same reason). With no symlink, every
        // Storage::disk('public')->url() 404'd in production for every
        // feature using it, silently, from the first release onward; found
        // 2026-10-02 when a carousel image uploaded fine but its URL 404'd.
        //
        // Instead the root points at a `storage/` directory inside the LIVE
        // VHOST DOCROOT (public_html/admin), which is:
        //   - directly web-served, so no symlink and no PHP streaming route
        //   - a fixed vhost path the deploy never renames (the atomic switch
        //     renames laravel-admin/, never the docroot), so uploads survive
        //     every release automatically with NO sync-forward step — unlike
        //     public_path(), which lives inside laravel-admin/ and would be
        //     carried away to _previous-* on every single switch
        //   - only ever written by PhotoUploadService::promoteToPublic(), so
        //     private originals (uploads_private, below) never land here
        // release-manager.php's `stage` creates it and asserts it, and its
        // `switch` + `smoke-test-live` gate on a sentinel file surviving the
        // swap byte-identical over real HTTP.
        //
        // Resolution order: explicit env override, then auto-detected
        // production docroot (laravel-admin/../public_html/admin), then the
        // stock Laravel path for local dev, where storage:link works fine.
        'public' => [
            'driver' => 'local',
            'root' => public_uploads_root(),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // §38: originals from public forms (membership-application photos,
        // committee-submission photos, member public-profile edits) land
        // here — private by default, never web-accessible, never linked via
        // storage:link. Only an explicitly-approved derivative ever moves to
        // the 'public' disk above (see App\Services\PhotoUploadService).
        'uploads_private' => [
            'driver' => 'local',
            'root' => storage_path('app/private/uploads'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
