<?php

namespace App\Services\Mail;

use App\Models\MailIdentity;
use App\Models\Mailbox;
use App\Services\Mail\Data\MailAddress;
use App\Services\Mail\Data\MailMessage;
use Illuminate\Support\Collection;

/**
 * Which institutional identity a message arrived through (docs/MAIL_CENTER.md, "Which identity received a message").
 *
 * One physical mailbox receives the mail of every alias, so the only way to tell them apart is the addressing on the
 * message itself. Nothing here guesses: an address matches an identity or it does not.
 *
 *   - Candidates are taken from EVERY recipient field the provider reports — To, Cc, Bcc — and from any envelope
 *     header the caller could obtain (Delivered-To, Envelope-To, X-Original-To), not from one display header alone.
 *   - A message may match several identities (addressed to support@ and partnerships@ together), exactly one, or none.
 *   - None is a normal, expected outcome: mail delivered only by envelope (a bare Bcc, a mailing list, a forward) names
 *     no institutional address in its headers. Such a message is UNCLASSIFIED — it is never hidden, never dropped and
 *     never attributed to an identity it did not come through. "All mail" always shows everything the mailbox holds.
 */
final class MailIdentityResolver
{
    /** Envelope headers a receiving server adds that name the address the message was actually delivered for. */
    public const ENVELOPE_HEADERS = ['delivered-to', 'envelope-to', 'x-original-to', 'x-forwarded-to'];

    /**
     * The enabled, receiving identities of a mailbox, keyed by lower-case address.
     *
     * @return Collection<string, MailIdentity>
     */
    public function receivingIdentities(Mailbox $mailbox): Collection
    {
        return MailIdentity::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->enabled()->where('can_receive', true)->ordered()
            ->get()
            ->keyBy(fn (MailIdentity $identity) => strtolower((string) $identity->address));
    }

    /**
     * The identities a message was addressed to, in registry order.
     *
     * @param  list<string>  $envelopeAddresses  addresses lifted from envelope headers, when the caller has the source
     * @return list<MailIdentity>
     */
    public function identitiesFor(Mailbox $mailbox, MailMessage $message, array $envelopeAddresses = []): array
    {
        $known = $this->receivingIdentities($mailbox);
        if ($known->isEmpty()) {
            return [];
        }
        $matched = [];
        foreach ($this->candidateAddresses($message, $envelopeAddresses) as $address) {
            $identity = $known->get($address);
            if ($identity !== null && ! isset($matched[$identity->getKey()])) {
                $matched[$identity->getKey()] = $identity;
            }
        }

        // Registry order, so the same message always lists its identities the same way.
        return $known->filter(fn (MailIdentity $i) => isset($matched[$i->getKey()]))->values()->all();
    }

    /**
     * Every address that could name the identity this message came through, lower-cased and de-duplicated.
     *
     * @param  list<string>  $envelopeAddresses
     * @return list<string>
     */
    public function candidateAddresses(MailMessage $message, array $envelopeAddresses = []): array
    {
        $addresses = [];
        foreach ([...$message->to, ...$message->cc, ...$message->bcc] as $address) {
            if ($address instanceof MailAddress && $address->address !== '') {
                $addresses[] = strtolower($address->address);
            }
        }
        foreach ($envelopeAddresses as $address) {
            $address = strtolower(trim($address));
            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * The addresses named by the envelope headers of a raw RFC822 source. Used only where the source is already at
     * hand: fetching it per message would cost one extra provider request each, which the message list must not do.
     *
     * @return list<string>
     */
    public static function envelopeAddressesFromSource(string $source): array
    {
        // Headers only: the body may quote anything, including another institutional address.
        $head = (string) preg_split("/\r?\n\r?\n/", $source, 2)[0];
        $found = [];
        foreach (preg_split('/\R/', $head) ?: [] as $line) {
            if (preg_match('/^([A-Za-z-]+):\s*(.+)$/', $line, $m) !== 1) {
                continue;
            }
            if (! in_array(strtolower($m[1]), self::ENVELOPE_HEADERS, true)) {
                continue;
            }
            if (preg_match('/<([^<>@\s]+@[^<>@\s]+)>|([^\s<>,;]+@[^\s<>,;]+)/', $m[2], $a) === 1) {
                $address = strtolower(trim($a[1] !== '' ? $a[1] : ($a[2] ?? '')));
                if ($address !== '') {
                    $found[] = $address;
                }
            }
        }

        return array_values(array_unique($found));
    }
}
