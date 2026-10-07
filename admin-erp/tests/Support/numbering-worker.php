<?php

/**
 * One worker of Tests\Feature\Admin\MembershipNumberingConcurrencyTest: a separate PHP process with its own database
 * connection, so several of them really run at the same time (a test process alone can never race itself).
 *
 *   php numbering-worker.php approve <application id> <start-at> <admin user id>
 *   php numbering-worker.php apply   <base64 json payload> <start-at>
 *
 * Every worker waits until <start-at> (a microtime) before acting, so they all hit the database together. It prints one
 * JSON line: {"ok":…,"number":…}. It runs against the TEST database (the environment is the test runner's), never the
 * network (HTTP faked, no revalidation secret) and never sends mail.
 */

use App\Models\MembershipApplication;
use App\Models\User;
use App\Services\MembershipApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['services.public_site.revalidate_secret' => null]);
Http::fake();
Http::preventStrayRequests();

[$mode, $argument, $startAt] = [$argv[1] ?? '', $argv[2] ?? '', (float) ($argv[3] ?? 0)];

try {
    if ($mode === 'approve') {
        $application = MembershipApplication::query()->findOrFail((int) $argument);
        $admin = User::query()->findOrFail((int) ($argv[4] ?? 0));
        while (microtime(true) < $startAt) {
            usleep(200);
        }
        $result = $app->make(MembershipApprovalService::class)->approve($application, $admin);
        echo json_encode(['ok' => true, 'number' => $result->membership?->member_code, 'already' => $result->alreadyApproved]), "\n";
    } elseif ($mode === 'apply') {
        $payload = json_decode((string) base64_decode($argument), true);
        $request = Request::create('/api/v1/public/membership/applications', 'POST', is_array($payload) ? $payload : [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        while (microtime(true) < $startAt) {
            usleep(200);
        }
        $response = $kernel->handle($request);
        $body = json_decode((string) $response->getContent(), true);
        echo json_encode(['ok' => $response->getStatusCode() === 201, 'status' => $response->getStatusCode(), 'number' => $body['data']['application_no'] ?? null]), "\n";
    } else {
        throw new InvalidArgumentException("unknown mode {$mode}");
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class.': '.$e->getMessage()]), "\n";
    exit(1);
}
