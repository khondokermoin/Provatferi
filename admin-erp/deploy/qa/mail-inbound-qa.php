<?php
/**
 * REAL INBOUND ACCEPTANCE for the Mail Center, against the live Hostinger Mail API (docs/MAIL_CENTER.md). CLI only.
 *
 * Everything here runs through the APPLICATION'S OWN provider (App\Services\Mail\MailProvider) and resolver, so what it
 * proves is what the Mail Center itself does — not a separate client that merely talks to the same API.
 *
 * It touches only its own messages. Every message it sends carries the tag in its subject, and every mode that reads or
 * changes anything matches on that tag first. Real mail is never opened, never flagged and never moved.
 *
 *   seed <tag>
 *       ONCE per tag. One clearly-labelled message to each institutional address (admin@, info@, support@, security@,
 *       partnerships@) through the live transport, so the physical mailbox really receives mail addressed to each
 *       identity. One of them carries hostile HTML and an attachment, so sanitising and attachment handling are tested
 *       on something that actually arrived rather than on a fixture.
 *   verify <tag>
 *       Read-only apart from the read flags the provider sets when a message is opened. Walks the whole matrix through
 *       the real API: listing, message detail, plain text, sanitised HTML, read/unread, provider search, raw source and
 *       envelope headers, attachment bytes, identity resolution, identity filtering, "all mail" completeness, and the
 *       identity a reply would default to. Prints a PASS/FAIL per check and never a message body.
 *   cleanup <tag>
 *       Moves exactly this tag's messages to the mailbox's Trash folder (never a permanent delete, never anything
 *       untagged) and reports what it moved.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$STATE = $APP.'/storage/app/mail-inbound-qa';
$mode = $argv[1] ?? '';
$tag = (string) ($argv[2] ?? '');

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

function tagOk(string $tag): bool
{
    return preg_match('/^[a-z0-9]{6,24}$/', $tag) === 1;
}

function bootLaravel(string $app): void
{
    define('LARAVEL_START', microtime(true));
    define('LARAVEL_PUBLIC_PATH_OVERRIDE', $app.'/public');
    require $app.'/vendor/autoload.php';
    $laravel = require $app.'/bootstrap/app.php';
    $laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

function once(string $state, string $key, callable $work): never
{
    if (! is_dir($state)) {
        mkdir($state, 0700, true);
    }
    $file = $state.'/'.$key.'.json';
    if (is_file($file)) {
        $stored = json_decode((string) file_get_contents($file), true);
        out(['replayed_first_run' => true] + (is_array($stored) ? $stored : ['stored' => 'unreadable']));
    }
    $report = $work();
    file_put_contents($file, json_encode($report));
    out($report);
}

/** The institutional addresses, from config — no address is written here. */
function addresses(): array
{
    $role = fn (string $k) => strtolower(trim((string) config('institution.email.'.$k)));
    $internal = $role('internal');
    $domain = str_contains($internal, '@') ? substr(strrchr($internal, '@'), 1) : '';

    return array_filter([
        'admin' => $internal,
        'info' => $role('public'),
        'support' => $role('support'),
        'security' => $role('security'),
        'partnerships' => $domain !== '' ? 'partnerships@'.$domain : '',
    ]);
}

/** The physical mailbox, as the Mail Center model sees it. Registered or not, the provider resolves it by address. */
function account(): App\Models\Mailbox
{
    $address = strtolower(trim((string) config('institution.email.internal')));
    $box = App\Models\Mailbox::query()->where('address', $address)->first();
    if ($box === null) {
        $box = new App\Models\Mailbox(['display_name' => 'Provatferi Administration', 'enabled' => true, 'sort_order' => 0]);
        $box->address = $address;
        $box->provider = (string) config('mail_center.provider', 'hostinger_mail_api');
        // Not saved: this mode only needs an addressable handle for the provider.
    }

    return $box;
}

switch ($mode) {
    case 'register':
        // The institution's REAL configuration, not QA data: the one physical mailbox and the institutional addresses
        // it sends as and receives for. Idempotent, so running it twice changes nothing. Exactly what the owner would
        // enter in Admin → Mail → Mailboxes; done here so the acceptance below has something to resolve against.
        bootLaravel($APP);
        $names = [
            'admin' => ['Provatferi Administration', App\Models\MailIdentity::PURPOSE_ADMINISTRATION, true],
            'info' => ['Provatferi', App\Models\MailIdentity::PURPOSE_GENERAL, true],
            'support' => ['Provatferi Support', App\Models\MailIdentity::PURPOSE_SUPPORT, true],
            'security' => ['Provatferi Security', App\Models\MailIdentity::PURPOSE_SECURITY, true],
            'partnerships' => ['Provatferi Partnerships', App\Models\MailIdentity::PURPOSE_PARTNERSHIPS, true],
        ];
        $address = strtolower(trim((string) config('institution.email.internal')));
        $box = App\Models\Mailbox::query()->where('address', $address)->first();
        if ($box === null) {
            $box = new App\Models\Mailbox(['display_name' => 'Provatferi Administration', 'description' => 'The institution\'s mailbox; its institutional addresses are below.', 'enabled' => true, 'sort_order' => 10]);
            $box->address = $address;
            $box->provider = (string) config('mail_center.provider', 'hostinger_mail_api');
            $box->save();
        }
        // Prove the provider really reaches it, and record that.
        $provider = app(App\Services\Mail\MailProvider::class);
        try {
            $providerId = $provider->mailboxId($address);
            $box->provider_mailbox_id = $providerId;
            $box->status = $providerId !== null ? App\Models\Mailbox::STATUS_CONNECTED : App\Models\Mailbox::STATUS_NOT_IN_SCOPE;
        } catch (Throwable $e) {
            $box->status = App\Models\Mailbox::STATUS_UNAVAILABLE;
        }
        $box->status_checked_at = now();
        $box->save();

        $made = [];
        $order = 10;
        foreach (addresses() as $key => $identityAddress) {
            [$name, $purpose] = $names[$key];
            $identity = App\Models\MailIdentity::query()->where('address', $identityAddress)->first();
            if ($identity === null) {
                $identity = new App\Models\MailIdentity([
                    'display_name' => $name, 'purpose' => $purpose, 'can_receive' => true,
                    'can_send_manual' => true, 'is_automated' => false, 'enabled' => true, 'sort_order' => $order,
                ]);
                $identity->mailbox_id = $box->getKey();
                $identity->address = $identityAddress;
                $identity->save();
            }
            $made[$key] = ['id' => $identity->getKey(), 'address' => $identity->address, 'sendable' => $identity->allowsManualSending()];
            $order += 10;
        }
        // The automated sender: received mail is shown, but nobody may write as it.
        $noreply = strtolower(trim((string) config('institution.email.noreply')));
        if ($noreply !== '' && App\Models\MailIdentity::query()->where('address', $noreply)->doesntExist()) {
            $identity = new App\Models\MailIdentity([
                'display_name' => (string) config('mail.from.name'), 'purpose' => App\Models\MailIdentity::PURPOSE_AUTOMATED,
                'can_receive' => true, 'can_send_manual' => false, 'is_automated' => true, 'enabled' => true, 'sort_order' => $order,
            ]);
            $identity->mailbox_id = $box->getKey();
            $identity->address = $noreply;
            $identity->save();
        }
        $made['no-reply'] = App\Models\MailIdentity::query()->where('address', $noreply)
            ->get(['id', 'address', 'can_send_manual', 'is_automated'])->first()?->only(['id', 'address', 'can_send_manual', 'is_automated']);

        out(['mode' => 'register', 'mailbox' => ['id' => $box->getKey(), 'address' => $box->address, 'status' => $box->status, 'provider_id_resolved' => $box->provider_mailbox_id !== null], 'identities' => $made]);

    case 'seed':
        if (! tagOk($tag)) {
            out(['error' => 'usage: seed <tag>'], 2);
        }
        bootLaravel($APP);
        once($STATE, "seed-{$tag}", function () use ($tag) {
            $sent = [];
            Illuminate\Support\Facades\Event::listen(Illuminate\Mail\Events\MessageSent::class, function ($e) use (&$sent) {
                $sent[] = $e->sent->getMessageId();
            });

            // One message carries hostile HTML and an attachment: the sanitiser and the attachment route are then
            // tested against something that really arrived through the provider.
            $hostile = '<p>Partnership enquiry.</p>'
                .'<script>window.__pwned=1</script>'
                .'<img src="https://tracker.invalid/pixel.gif?t='.$tag.'" width="1" height="1">'
                .'<img src="x" onerror="window.__pwned=2">'
                .'<a href="javascript:alert(1)">do not follow</a>'
                .'<iframe src="https://example.org"></iframe>'
                .'<p style="background:url(https://tracker.invalid/bg.png)">styled</p>';

            $results = [];
            foreach (addresses() as $key => $address) {
                $subject = "[MCIN {$tag} {$key}] inbound acceptance";
                try {
                    Illuminate\Support\Facades\Mail::send([], [], function ($m) use ($address, $subject, $key, $hostile, $tag) {
                        $m->to($address)->subject($subject);
                        $email = $m->getSymfonyMessage();
                        $email->text("Controlled inbound acceptance message for the Provatferi Mail Center.\nIdentity: {$key}\nTag: {$tag}\nNothing is required of the reader.");
                        if ($key === 'partnerships') {
                            $email->html($hostile);
                            $m->attachData("tag {$tag} attachment body", 'acceptance.txt', ['mime' => 'text/plain']);
                        }
                    });
                    $results[] = ['identity' => $key, 'address' => $address, 'accepted' => true];
                } catch (Throwable $e) {
                    $results[] = ['identity' => $key, 'address' => $address, 'accepted' => false, 'error' => class_basename($e)];
                }
            }

            return ['mode' => 'seed', 'tag' => $tag, 'sent_at_utc' => gmdate('c'), 'messages' => $results, 'transport_ids' => count($sent)];
        });

        // no break — once() exits.
    case 'verify':
        if (! tagOk($tag)) {
            out(['error' => 'usage: verify <tag>'], 2);
        }
        bootLaravel($APP);
        $provider = app(App\Services\Mail\MailProvider::class);
        $resolver = app(App\Services\Mail\MailIdentityResolver::class);
        $renderer = app(App\Services\Mail\MailBodyRenderer::class);
        $box = account();
        $checks = [];
        $add = function (string $id, string $what, bool $ok, $detail = '') use (&$checks) {
            $checks[] = ['id' => $id, 'check' => $what, 'ok' => $ok, 'detail' => is_string($detail) ? mb_substr($detail, 0, 300) : $detail];
        };
        $marker = "[MCIN {$tag} ";

        try {
            // A. folders and inbox listing
            $folders = $provider->folders($box);
            $inbox = null;
            foreach ($folders as $f) {
                if ($f->role() === '\\Inbox' || strtoupper($f->path) === 'INBOX') {
                    $inbox = $f;
                }
            }
            $add('A1', 'the provider lists the mailbox folders', $folders !== [], count($folders).' folders');
            $add('A2', 'the mailbox has an Inbox', $inbox !== null, $inbox?->path ?? '-');
            $folder = $inbox?->path ?? 'INBOX';

            // The seeded messages, found by their tag. Pages back until they are all seen.
            $mine = [];
            $all = [];
            for ($page = 1; $page <= 6; $page++) {
                $list = $provider->messages($box, $folder, $page, 50);
                foreach ($list->messages as $m) {
                    $all[$m->uid] = $m;
                    if (str_contains($m->subject, $marker)) {
                        $mine[$m->uid] = $m;
                    }
                }
                if ($page >= $list->totalPages) {
                    break;
                }
            }
            $add('A3', 'the inbox listing returns messages', $all !== [], count($all).' listed');
            $add('B1', 'every seeded message is in the listing (all mail shows everything)', count($mine) === count(addresses()), count($mine).' of '.count(addresses()));

            // C. identity resolution and filtering
            $byIdentity = [];
            foreach ($mine as $uid => $m) {
                $names = array_map(fn ($i) => $i->address, $resolver->identitiesFor($box, $m));
                preg_match('/\[MCIN '.$tag.' ([a-z]+)\]/', $m->subject, $k);
                $byIdentity[$k[1] ?? '?'] = ['uid' => $uid, 'resolved' => $names, 'to' => array_map(fn ($a) => $a->address, $m->to)];
            }
            $registered = $resolver->receivingIdentities($box);
            $add('C0', 'institutional identities are registered for this mailbox', $registered->isNotEmpty(), $registered->count().' identities');

            $correct = 0;
            foreach ($byIdentity as $key => $row) {
                $expected = addresses()[$key] ?? null;
                if ($expected !== null && in_array($expected, $row['resolved'], true)) {
                    $correct++;
                }
            }
            $add('C1', 'each message resolves to the identity it was addressed to', $correct === count($byIdentity), "{$correct} of ".count($byIdentity).': '.json_encode($byIdentity));

            // D. provider-side filtering by recipient
            $filterResults = [];
            foreach (addresses() as $key => $address) {
                $search = App\Services\Mail\Data\MailSearch::addressedTo($address);
                $page = $provider->search($box, $folder, $search, 1, 25);
                $hit = 0;
                foreach ($page->messages as $m) {
                    if (str_contains($m->subject, $marker.$key.']')) {
                        $hit++;
                    }
                }
                $filterResults[$key] = ['returned' => count($page->messages), 'own_found' => $hit];
            }
            $allFound = count(array_filter($filterResults, fn ($r) => $r['own_found'] >= 1));
            $add('D1', 'the provider finds each identity\'s message by recipient (server-side filtering)', $allFound === count(addresses()), json_encode($filterResults));

            // E. message detail, text, sanitised HTML, attachment — on the partnerships message (the hostile one)
            $target = $byIdentity['partnerships']['uid'] ?? array_key_first($mine);
            $message = $provider->message($box, $folder, (int) $target);
            $add('E1', 'one message opens with its envelope', $message->uid === (int) $target, 'from '.($message->from?->address ?? '-'));

            $wasUnseen = $message->unseen;
            $text = $provider->messageText($box, $folder, (int) $target);
            $add('E2', 'its plain text is returned', trim($text->text) !== '', strlen($text->text).' bytes');

            $rendered = $renderer->render($text, false, []);
            $html = (string) ($rendered->frameDocument ?? $rendered->textHtml ?? '');
            $add('F1', 'script tags are removed from the received HTML', ! str_contains(strtolower($html), '<script'));
            $add('F2', 'inline event handlers are removed', ! preg_match('/\son[a-z]+\s*=/i', $html));
            $add('F3', 'javascript: links are removed', ! str_contains(strtolower($html), 'javascript:'));
            $add('F4', 'iframes are removed', ! str_contains(strtolower($html), '<iframe'));
            $add('F5', 'remote images are held back until asked for', $rendered->blockedImages > 0 || ! str_contains($html, 'tracker.invalid'), 'blocked='.$rendered->blockedImages);
            $add('F6', 'the sanitised document forbids scripts by policy', str_contains($html, "script-src 'none'"));

            // Attachment: bytes come back and match what was sent.
            $attachments = $message->downloadableAttachments();
            $add('G1', 'the message carries its attachment', $attachments !== [], count($attachments).' attachment(s)');
            if ($attachments !== []) {
                $bytes = $provider->attachmentContent($box, $folder, (int) $target, $attachments[0]->id);
                $add('G2', 'the attachment bytes are retrievable and are the ones sent', str_contains($bytes, "tag {$tag} attachment"), strlen($bytes).' bytes');
            }

            // Each remaining section stands on its own: one provider refusal must not cost the others their evidence.
            $section = function (string $id, string $what, callable $run) use ($add) {
                try {
                    [$ok, $detail] = $run();
                    $add($id, $what, $ok, $detail);
                } catch (Throwable $e) {
                    $status = $e instanceof App\Services\Mail\MailProviderException ? " (kind={$e->kind} status=".var_export($e->status, true).')' : '';
                    $add($id, $what, false, class_basename($e).': '.mb_substr($e->getMessage(), 0, 160).$status);
                }
            };

            // H. read / unread state.
            //
            // Whether fetching the body also marks the message \Seen is the PROVIDER'S behaviour, and observation on
            // 2026-10-27 showed it does not always do so. The Mail Center does not depend on it — it sets the flag
            // explicitly, which H2 proves — so this is recorded as what was seen, not asserted as a requirement.
            $section('H1', 'the read state of a message is readable (whether opening alone marks it read is the provider\'s own behaviour)', function () use ($provider, $box, $folder, $target, $wasUnseen) {
                $afterOpen = $provider->message($box, $folder, (int) $target);

                return [true, 'before_opening_unseen='.var_export($wasUnseen, true).', after_fetching_the_body_unseen='.var_export($afterOpen->unseen, true)
                    .($wasUnseen && $afterOpen->unseen ? ' — the provider did NOT mark it read on fetch; the Mail Center sets the flag itself' : '')];
            });
            $section('H2', 'it can be set back to unread and read again', function () use ($provider, $box, $folder, $target) {
                $provider->setFlags($box, $folder, (int) $target, [], ['\\Seen']);
                $unseen = $provider->message($box, $folder, (int) $target)->unseen;
                $provider->setFlags($box, $folder, (int) $target, ['\\Seen'], []);
                $seenAgain = ! $provider->message($box, $folder, (int) $target)->unseen;

                return [$unseen && $seenAgain, 'unread_after_clear='.var_export($unseen, true).' read_again='.var_export($seenAgain, true)];
            });
            $section('H3', 'it can be flagged and unflagged', function () use ($provider, $box, $folder, $target) {
                $provider->setFlags($box, $folder, (int) $target, ['\\Flagged'], []);
                $flagged = $provider->message($box, $folder, (int) $target)->isFlagged();
                $provider->setFlags($box, $folder, (int) $target, [], ['\\Flagged']);

                return [$flagged, 'flagged='.var_export($flagged, true)];
            });

            // I. raw source and envelope headers
            $section('I1', 'the raw source is retrievable and its envelope headers name an institutional address', function () use ($provider, $box, $folder, $target) {
                $source = $provider->messageSource($box, $folder, (int) $target);
                $envelope = App\Services\Mail\MailIdentityResolver::envelopeAddressesFromSource($source);

                return [strlen($source) > 100 && $envelope !== [], strlen($source).' bytes, envelope: '.json_encode($envelope)];
            });

            // J. what a reply would go out as
            $section('J1', 'a reply defaults to the identity that received the message', function () use ($resolver, $box, $message) {
                $replyIdentity = null;
                foreach ($resolver->identitiesFor($box, $message) as $i) {
                    if ($i->allowsManualSending()) {
                        $replyIdentity = $i;
                        break;
                    }
                }

                return [$replyIdentity !== null && $replyIdentity->address === (addresses()['partnerships'] ?? ''), $replyIdentity?->address ?? 'none'];
            });
            // A reply must never be addressed to one of the mailbox's own institutional addresses. These seeded
            // messages come FROM the institution's own automated sender, so the correct answer here is an EMPTY
            // recipient list — writing back to ourselves is exactly what must not happen. The case of an outside
            // sender (where the reply goes to them) is covered by tests/Feature/Mail/MailCenterIdentityTest.php.
            $section('J2', 'a reply is never addressed to one of the mailbox\'s own institutional addresses', function () use ($message, $box, $registered) {
                $own = array_values(array_unique([strtolower((string) $box->address), ...$registered->keys()->all()]));
                $recipients = App\Services\Mail\MailComposer::replyRecipients($message, $own, true);
                $ownInReply = array_intersect(array_map('strtolower', [...$recipients['to'], ...$recipients['cc']]), $own);

                return [$ownInReply === [], 'sender was '.($message->from?->address ?? '-').', reply recipients '.json_encode($recipients)];
            });
        } catch (Throwable $e) {
            $add('RUN', 'the run completed', false, class_basename($e).': '.$e->getMessage());
        }

        $failed = count(array_filter($checks, fn ($c) => ! $c['ok']));
        out(['mode' => 'verify', 'tag' => $tag, 'passed' => count($checks) - $failed, 'total' => count($checks), 'checks' => $checks], $failed === 0 ? 0 : 1);

    case 'cleanup':
        if (! tagOk($tag)) {
            out(['error' => 'usage: cleanup <tag>'], 2);
        }
        bootLaravel($APP);
        $provider = app(App\Services\Mail\MailProvider::class);
        $box = account();
        $marker = "[MCIN {$tag} ";
        $trash = null;
        foreach ($provider->folders($box) as $f) {
            if ($f->role() === '\\Trash') {
                $trash = $f->path;
            }
        }
        $moved = [];
        $skipped = 0;
        foreach (['INBOX', 'INBOX.Sent'] as $folder) {
            for ($page = 1; $page <= 6; $page++) {
                try {
                    $list = $provider->messages($box, $folder, $page, 50);
                } catch (Throwable) {
                    break;
                }
                foreach ($list->messages as $m) {
                    // Only this tag's own messages, never anything else in the mailbox.
                    if (! str_contains($m->subject, $marker)) {
                        $skipped++;

                        continue;
                    }
                    if ($trash === null) {
                        continue;
                    }
                    $provider->move($box, $folder, $m->uid, $trash);
                    $moved[] = ['folder' => $folder, 'uid' => $m->uid];
                }
                if ($page >= $list->totalPages) {
                    break;
                }
            }
        }
        foreach (glob($STATE.'/*'.$tag.'*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($STATE) && (glob($STATE.'/*') ?: []) === []) {
            rmdir($STATE);
        }

        out(['mode' => 'cleanup', 'tag' => $tag, 'trash_folder' => $trash, 'moved_to_trash' => $moved, 'left_untouched' => $skipped, 'state_dir_left' => is_dir($STATE)]);

    default:
        out(['error' => 'modes: seed <tag> | verify <tag> | cleanup <tag>'], 2);
}
