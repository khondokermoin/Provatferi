<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Split-layout front controller (production only)
|--------------------------------------------------------------------------
|
| This file lives in the PUBLIC docroot:
|   /home/u951246149/domains/provatferi.org/public_html/admin/
|
| The actual Laravel application (app/, vendor/, bootstrap/, storage/, etc.)
| lives one level above public_html, in a private, non-web-accessible
| sibling directory:
|   /home/u951246149/domains/provatferi.org/laravel-admin/
|
| LARAVEL_PUBLIC_PATH_OVERRIDE must be defined BEFORE bootstrap/app.php is
| required — bootstrap/app.php checks for this constant and calls
| $app->usePublicPath(...) with it. __DIR__ here is this file's own
| directory (public_html/admin/), so Laravel's public_path() helper
| (used by asset()/Vite manifest lookups) resolves to the real web docroot
| instead of the private laravel-admin/public/ directory, which is never
| served and does not need to hold built assets.
|
| Do NOT replace this with an env()-based override (LARAVEL_PUBLIC_PATH via
| getenv()) — that was tried previously and is unreliable on this shared
| hosting setup because per-request process env vars aren't guaranteed to
| propagate to PHP-FPM. The constant, defined directly in this file, is the
| proven approach.
*/
define('LARAVEL_PUBLIC_PATH_OVERRIDE', __DIR__);

$privateApp = __DIR__.'/../../laravel-admin';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $privateApp.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $privateApp.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $privateApp.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
