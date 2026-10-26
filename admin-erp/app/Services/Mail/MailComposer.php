<?php

namespace App\Services\Mail;

use App\Models\MailIdentity;
use App\Services\Mail\Data\MailAddress;
use App\Services\Mail\Data\MailMessage;
use App\Services\Mail\Data\MailMessageText;
use App\Services\Mail\Data\OutgoingAttachment;
use App\Services\Mail\Data\OutgoingMessage;
use App\Support\AdminTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Builds the messages the Mail Center sends (docs/MAIL_CENTER.md, "Compose, reply, forward"). No address from the request
 * ever becomes a sender: the institutional identity was resolved and authorised by MailboxAccess::resolveIdentity()
 * before anything here runs, and only its registered name travels into the message. A request's own "from", "sender" or
 * "reply_to" field is never read.
 *
 * Recipients are typed as one line per field, separated by commas or semicolons. A line break, a NUL or any other control
 * character in a recipient or subject field is refused outright (header injection), and every address must pass strict
 * RFC validation. Quoted text in a reply or forward is taken from the provider at send time — never from the browser.
 */
final class MailComposer
{
    /** Refused anywhere in a header-bound field. */
    public const HEADER_INJECTION = '/[\x00-\x1F\x7F]/u';

    /**
     * @param  array<string, mixed>  $input  validated request data (to, cc, bcc, subject, body)
     * @param  list<UploadedFile>  $uploads
     */
    public function compose(MailIdentity $identity, array $input, array $uploads = []): OutgoingMessage
    {
        [$to, $cc, $bcc] = $this->recipients($input);
        $body = $this->body($input);

        return new OutgoingMessage($this->displayName($identity), $to, $cc, $bcc, $this->subject($input), $body, self::htmlFromText($body), $this->uploaded($uploads));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<UploadedFile>  $uploads
     */
    public function reply(MailIdentity $identity, MailMessage $original, MailMessageText $originalText, array $input, array $uploads = []): OutgoingMessage
    {
        [$to, $cc, $bcc] = $this->recipients($input);
        $body = $this->body($input);
        $quoted = ($input['include_quote'] ?? true) ? self::quote($original, $originalText) : '';
        $text = trim($body)."\n\n".$quoted;
        $html = self::htmlFromText($body).($quoted !== '' ? self::quoteHtml($original, $originalText) : '');

        return new OutgoingMessage($this->displayName($identity), $to, $cc, $bcc, $this->subject($input), rtrim($text)."\n", $html, $this->uploaded($uploads),
            inReplyTo: ['uid' => $original->uid, 'folder' => $original->folder],
            inReplyToMessageId: $original->messageId,
            references: array_values(array_filter([$original->messageId])));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<UploadedFile>  $uploads
     * @param  list<OutgoingAttachment>  $forwarded  the original's attachments the person chose to include
     */
    public function forward(MailIdentity $identity, MailMessage $original, MailMessageText $originalText, array $input, array $uploads = [], array $forwarded = []): OutgoingMessage
    {
        [$to, $cc, $bcc] = $this->recipients($input);
        $body = $this->body($input, required: false);
        $block = self::forwardBlock($original, $originalText);
        $text = (trim($body) !== '' ? trim($body)."\n\n" : '').$block;
        $html = (trim($body) !== '' ? self::htmlFromText($body) : '').self::forwardHtml($original, $originalText);

        return new OutgoingMessage($this->displayName($identity), $to, $cc, $bcc, $this->subject($input), $text, $html, [...$forwarded, ...$this->uploaded($uploads)],
            forwardOf: ['uid' => $original->uid, 'folder' => $original->folder],
            inReplyToMessageId: $original->messageId,
            references: array_values(array_filter([$original->messageId])));
    }

    /**
     * Who a reply goes to: the sender (the provider does not report a Reply-To header). Reply-all adds every other To and
     * Cc recipient. The mailbox's own address is never a recipient of its own reply.
     *
     * @return array{to: list<string>, cc: list<string>}
     */
    public static function replyRecipients(MailMessage $original, array $ownAddresses, bool $all): array
    {
        // Every address this mailbox answers to — its own and all of its institutional identities. A reply to a message
        // addressed to support@ must not be sent back to support@, whichever identity it goes out as.
        $own = array_map('strtolower', $ownAddresses);
        $isOwn = fn (string $address) => in_array(strtolower($address), $own, true);

        $to = $original->from !== null && ! $isOwn($original->from->address) ? [$original->from->address] : [];
        if ($to === [] && $original->to !== []) {
            // A message the mailbox itself sent (in Sent): answering it means writing to its recipients again.
            $to = array_values(array_unique(array_filter(array_map(fn (MailAddress $a) => $a->address, $original->to), fn ($a) => ! $isOwn($a))));
        }
        $cc = [];
        if ($all) {
            foreach ([...$original->to, ...$original->cc] as $address) {
                if (! $isOwn($address->address) && ! in_array($address->address, $to, true) && ! in_array($address->address, $cc, true)) {
                    $cc[] = $address->address;
                }
            }
        }

        return ['to' => $to, 'cc' => $cc];
    }

    public static function replySubject(string $subject): string
    {
        $subject = self::oneLine($subject);

        return preg_match('/^\s*re\s*:/i', $subject) === 1 ? $subject : 'Re: '.$subject;
    }

    public static function forwardSubject(string $subject): string
    {
        $subject = self::oneLine($subject);

        return preg_match('/^\s*(fwd?|fw)\s*:/i', $subject) === 1 ? $subject : 'Fwd: '.$subject;
    }

    /** The original as quoted plain text: "On <date>, <sender> wrote:" and each line prefixed with "> ". */
    public static function quote(MailMessage $original, MailMessageText $text): string
    {
        $lines = preg_split('/\R/u', self::originalText($text)) ?: [];
        $quoted = implode("\n", array_map(fn ($line) => '> '.$line, $lines));

        return __('admin.mail.compose.quote_intro', ['date' => self::when($original), 'sender' => $original->from?->full() ?? '—'])."\n".$quoted;
    }

    /** The forwarded message's header block and its text, clearly marked as forwarded content. */
    public static function forwardBlock(MailMessage $original, MailMessageText $text): string
    {
        $head = [
            '---------- '.__('admin.mail.compose.forwarded_marker').' ----------',
            __('admin.mail.fields.from').': '.($original->from?->full() ?? '—'),
            __('admin.mail.fields.date').': '.self::when($original),
            __('admin.mail.fields.subject').': '.self::oneLine($original->subject),
            __('admin.mail.fields.to').': '.implode(', ', array_map(fn (MailAddress $a) => $a->full(), $original->to)),
        ];
        if ($original->cc !== []) {
            $head[] = __('admin.mail.fields.cc').': '.implode(', ', array_map(fn (MailAddress $a) => $a->full(), $original->cc));
        }

        return implode("\n", $head)."\n\n".self::originalText($text);
    }

    /** Plain text as minimal HTML: every character escaped, paragraphs and line breaks kept. */
    public static function htmlFromText(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];

        return implode('', array_map(fn ($p) => '<p>'.nl2br(e($p), false).'</p>', $paragraphs));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function recipients(array $input): array
    {
        $errors = [];
        $lists = [];
        foreach (['to', 'cc', 'bcc'] as $field) {
            [$addresses, $bad] = self::parseAddresses((string) ($input[$field] ?? ''));
            if ($bad !== []) {
                $errors[$field] = __('admin.mail.errors.bad_recipients', ['list' => implode(', ', array_map(fn ($b) => mb_substr($b, 0, 60), $bad))]);
            }
            $lists[$field] = $addresses;
        }
        if ($errors === [] && $lists['to'] === []) {
            $errors['to'] = __('admin.mail.errors.no_recipient');
        }
        $total = count($lists['to']) + count($lists['cc']) + count($lists['bcc']);
        if ($errors === [] && $total > (int) config('mail_center.compose.max_recipients', 50)) {
            $errors['to'] = __('admin.mail.errors.too_many_recipients', ['max' => (int) config('mail_center.compose.max_recipients', 50)]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$lists['to'], $lists['cc'], $lists['bcc']];
    }

    /**
     * Splits one recipient field. "Name <address>" keeps only the address (the provider takes bare addresses).
     *
     * @return array{0: list<string>, 1: list<string>} valid lower-case addresses (de-duplicated), refused entries
     */
    public static function parseAddresses(string $raw): array
    {
        if (preg_match(self::HEADER_INJECTION, $raw) === 1) {
            return [[], [__('admin.mail.errors.control_characters')]];
        }
        $valid = [];
        $bad = [];
        foreach (preg_split('/[,;]/', $raw) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $address = preg_match('/<([^<>]+)>\s*$/', $entry, $m) === 1 ? trim($m[1]) : $entry;
            $address = strtolower($address);
            $ok = mb_strlen($address) <= 254
                && Validator::make(['a' => $address], ['a' => 'email:rfc,strict'])->passes()
                && preg_match('/^[^\s"\'<>()\\\\]+@[a-z0-9.-]+\.[a-z]{2,}$/', $address) === 1;
            if (! $ok) {
                $bad[] = $entry;

                continue;
            }
            if (! in_array($address, $valid, true)) {
                $valid[] = $address;
            }
        }

        return [$valid, $bad];
    }

    /** @param array<string, mixed> $input */
    private function subject(array $input): string
    {
        $subject = (string) ($input['subject'] ?? '');
        if (preg_match(self::HEADER_INJECTION, $subject) === 1) {
            throw ValidationException::withMessages(['subject' => __('admin.mail.errors.control_characters')]);
        }

        return mb_substr(trim($subject), 0, (int) config('mail_center.compose.max_subject', 255));
    }

    /** @param array<string, mixed> $input */
    private function body(array $input, bool $required = true): string
    {
        $body = str_replace("\0", '', (string) ($input['body'] ?? ''));
        if ($required && trim($body) === '') {
            throw ValidationException::withMessages(['body' => __('admin.mail.errors.empty_body')]);
        }

        return $body;
    }

    /**
     * @param  list<UploadedFile>  $uploads
     * @return list<OutgoingAttachment>
     */
    private function uploaded(array $uploads): array
    {
        return array_values(array_map(fn (UploadedFile $file) => new OutgoingAttachment(
            self::safeFilename($file->getClientOriginalName()),
            $file->getMimeType() ?: 'application/octet-stream',
            (string) file_get_contents($file->getRealPath()),
        ), $uploads));
    }

    /** A filename without path, control characters or quotes, at most 150 characters (the extension is kept). */
    public static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '_', $name);
        $name = trim($name, " .\t");
        if ($name === '') {
            return 'attachment';
        }
        if (mb_strlen($name) > 150) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 150 - mb_strlen($ext) - 1).'.'.$ext;
        }

        return $name;
    }

    private function displayName(MailIdentity $identity): string
    {
        return self::oneLine($identity->label());
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }

    private static function when(MailMessage $original): string
    {
        return $original->date !== null ? AdminTime::local($original->date)->format('Y-m-d H:i') : '—';
    }

    /** The original's text: its plain part, or its HTML reduced to text when it has none. */
    private static function originalText(MailMessageText $text): string
    {
        $plain = trim(str_replace(["\r\n", "\r"], "\n", $text->text));
        if ($plain !== '') {
            return $plain;
        }
        $html = (string) preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", $text->html);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function quoteHtml(MailMessage $original, MailMessageText $text): string
    {
        return '<p>'.e(__('admin.mail.compose.quote_intro', ['date' => self::when($original), 'sender' => $original->from?->full() ?? '—'])).'</p>'
            .'<blockquote style="margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex">'.self::htmlFromText(self::originalText($text)).'</blockquote>';
    }

    private static function forwardHtml(MailMessage $original, MailMessageText $text): string
    {
        $block = self::forwardBlock($original, $text);
        [$head, $rest] = array_pad(explode("\n\n", $block, 2), 2, '');

        return '<div style="border-top:1px solid #ccc;margin-top:1em;padding-top:.5em"><p>'.nl2br(e($head), false).'</p>'.self::htmlFromText($rest).'</div>';
    }
}
