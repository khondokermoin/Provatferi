<?php

namespace App\Support;

use App\Models\MailIdentity;
use App\Services\Mail\Data\OutgoingAttachment;
use App\Services\Mail\Data\OutgoingMessage;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * THE ONE PLACE A HUMAN-WRITTEN MAIL CENTER MESSAGE GETS ITS IDENTITY HEADERS (2026-10-26; docs/MAIL_CENTER.md,
 * "Sending as an identity"). The sibling of App\Support\InstitutionMail, and deliberately separate from it:
 *
 *   InstitutionMail        AUTOMATED application mail. From is always the no-reply sender; Reply-To is the support or
 *                          security role; the internal mailbox is private and never appears in either header.
 *   MailCenterIdentityMail HUMAN correspondence a member of staff writes in the Mail Center. From AND Reply-To are both
 *                          the institutional identity the writer chose, so an answer comes back to the address the
 *                          conversation belongs to. The administration mailbox is a legitimate identity here, because a
 *                          person really does read it — the automated rule that keeps it out of From does not apply to
 *                          mail a person signed.
 *
 * The two never mix: nothing here is reachable from a notification, and InstitutionMail is not used for Mail Center
 * mail. Changing one cannot change the other.
 *
 * WHY SMTP AND NOT THE PROVIDER API. The Hostinger Mail API sends only as the mailbox the call is made for: its send
 * request carries a display name and no sender address. Proven on 2026-10-26, Hostinger's SMTP accepts an alias of the
 * authenticated mailbox as the sender and does not rewrite it — the visible From, the envelope Return-Path and the DKIM
 * signature (d=provatferi.org, covering `from`) all carry the chosen identity, so SPF, DKIM and DMARC align. No header
 * is faked: the provider genuinely supports this.
 */
final class MailCenterIdentityMail
{
    /**
     * Sends one Mail Center message as $identity and returns the Message-ID the transport assigned.
     *
     * @param  list<string>  $extraBcc  addresses added to Bcc (the mailbox itself, so a copy is kept — see the caller)
     *
     * @throws \Throwable whatever the transport throws; the caller turns it into a Mail Center failure
     */
    public static function send(MailIdentity $identity, OutgoingMessage $message, array $extraBcc = []): ?string
    {
        $address = (string) $identity->address;
        $name = InstitutionMail::headerText($identity->label());

        $sent = Mail::send([], [], function (Message $mail) use ($identity, $message, $extraBcc, $address, $name) {
            // From and Reply-To are the SAME institutional identity: a human conversation answers to the address it
            // was held at, never to the automated sender and never to the mailbox's own login.
            $mail->from($address, $name !== '' ? $name : null)
                ->replyTo($address, $name !== '' ? $name : null)
                ->subject(InstitutionMail::headerText($message->subject));

            foreach ($message->to as $recipient) {
                $mail->to($recipient);
            }
            foreach ($message->cc as $recipient) {
                $mail->cc($recipient);
            }
            foreach ([...$message->bcc, ...$extraBcc] as $recipient) {
                $mail->bcc($recipient);
            }

            /** @var Email $email */
            $email = $mail->getSymfonyMessage();
            if (trim($message->text) !== '') {
                $email->text($message->text);
            }
            if (trim($message->html) !== '') {
                $email->html($message->html);
            }
            if (trim($message->text) === '' && trim($message->html) === '') {
                $email->text('');
            }

            foreach ($message->attachments as $attachment) {
                /** @var OutgoingAttachment $attachment */
                $mail->attachData($attachment->content, $attachment->filename, ['mime' => $attachment->contentType]);
            }

            // Threading, from the provider's own identifiers for the message being answered or forwarded. Symfony
            // writes these as proper id headers; nothing is assembled by hand.
            $headers = $email->getHeaders();
            $parent = $message->inReplyToMessageId;
            if (is_string($parent) && $parent !== '') {
                $headers->addIdHeader('In-Reply-To', self::idOf($parent));
                $references = array_values(array_filter(array_map(
                    fn (string $id) => self::idOf($id),
                    [...$message->references, $parent],
                )));
                if ($references !== []) {
                    $headers->addIdHeader('References', $references);
                }
            }
        });

        return $sent?->getMessageId();
    }

    /** A Message-ID without its angle brackets, as Symfony's id headers expect it. */
    private static function idOf(string $messageId): string
    {
        return trim(trim($messageId), '<>');
    }
}
