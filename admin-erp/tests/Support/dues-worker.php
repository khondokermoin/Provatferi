<?php

/**
 * One worker of Tests\Feature\Admin\MembershipDuesConcurrencyTest: a separate PHP process with its own database
 * connection, so several of them really act on one membership's ledger at the same moment.
 *
 *   php dues-worker.php generate <membership id> <start-at> [admin user id]   the ledger's generation (an admin's button)
 *   php dues-worker.php command  -               <start-at>                   php artisan membership:generate-dues
 *   php dues-worker.php verify   <payment id>    <start-at> <admin user id>   verifying a recorded monthly payment
 *   php dues-worker.php verify-application <payment id> <start-at> <admin user id>   verifying a registration-fee payment
 *                                                                                    (Membership task 5: both issue the receipt)
 *
 * Every worker waits until <start-at> (a microtime) before acting. It prints one JSON line. It runs against the TEST
 * database (the environment is the test runner's), never the network, never sends mail.
 */

use App\Models\Payment;
use App\Models\User;
use App\Services\ApplicationPaymentService;
use App\Services\MembershipDueLedger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['services.public_site.revalidate_secret' => null]);
Http::fake();
Http::preventStrayRequests();

[$mode, $argument, $startAt, $adminId] = [$argv[1] ?? '', $argv[2] ?? '', (float) ($argv[3] ?? 0), $argv[4] ?? null];
$ledger = $app->make(MembershipDueLedger::class);
$admin = $adminId !== null ? User::query()->find((int) $adminId) : null;
$wait = function () use ($startAt): void {
    while (microtime(true) < $startAt) {
        usleep(200);
    }
};

try {
    if ($mode === 'generate') {
        $wait();
        echo json_encode(['ok' => true, 'created' => count($ledger->generateFor((int) $argument, $admin))]), "\n";
    } elseif ($mode === 'command') {
        $wait();
        $code = Artisan::call('membership:generate-dues');
        $report = json_decode(Artisan::output(), true);
        echo json_encode(['ok' => $code === 0, 'created' => $report['dues_created'] ?? null]), "\n";
    } elseif ($mode === 'verify') {
        $payment = Payment::query()->findOrFail((int) $argument);
        $wait();
        echo json_encode(['ok' => true, 'outcome' => $ledger->verifyPayment($payment, $admin)]), "\n";
    } elseif ($mode === 'verify-application') {
        $payment = Payment::query()->findOrFail((int) $argument);
        $wait();
        echo json_encode(['ok' => true, 'verified' => $app->make(ApplicationPaymentService::class)->verify($payment, $admin)]), "\n";
    } else {
        throw new InvalidArgumentException("unknown mode {$mode}");
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class.': '.$e->getMessage()]), "\n";
    exit(1);
}
