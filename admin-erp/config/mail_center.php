<?php

/*
|--------------------------------------------------------------------------
| Mail Center (docs/MAIL_CENTER.md)
|--------------------------------------------------------------------------
|
| Limits and timings only. WHICH mailboxes exist lives in the `mailboxes`
| table (the registry, managed from the admin panel), and WHO may use them in
| `mailbox_grants`: neither is ever decided here or in code.
|
| The provider credential is NOT in this file and never in the database: the
| Hostinger Mail API token is HOSTINGER_MAIL_API_TOKEN in the server's .env
| (config/services.php, `hostinger_mail.token`). Nothing in the Mail Center
| ever sends it, or anything derived from it, to a browser.
|
*/

return [

    // The provider every registered mailbox is reached through. One today; the registry stores it per mailbox.
    'provider' => 'hostinger_mail_api',

    'list' => [
        // Messages per page. The provider pages on its side, so a page costs one request whatever the mailbox size.
        'per_page' => 25,
    ],

    'cache' => [
        // Folder lists and their unread counts, shared by every user of a mailbox: at most one provider request per
        // mailbox per window, however many people have the Mail Center open.
        'folders_seconds' => 60,
    ],

    // How often an open admin page asks for the unread badge (only while the tab is visible).
    'poll_seconds' => 90,

    // Per account, per mailbox, per action (never per IP address alone). [attempts, decay seconds]
    'rate_limits' => [
        'send' => [20, 3600],
        'reply' => [60, 3600],
        'forward' => [30, 3600],
        // Any outgoing message (send, reply, forward) by one account from one mailbox, within a minute: stops bursts.
        'outgoing_burst' => [5, 60],
        // Every outgoing message from one mailbox, by everyone: protects the institution's sender reputation.
        'mailbox_hourly' => [100, 3600],
        'search' => [30, 60],
        'attachment' => [60, 60],
    ],

    'sending' => [
        // The provider API sends only as the mailbox itself and files its own copy in Sent. Any OTHER institutional
        // identity is an alias the API cannot send as, so it goes over SMTP — which leaves no copy behind, and the API
        // has no endpoint to append one. The mailbox is therefore Bcc'd on an alias send, so what went out under an
        // institutional name is still retrievable. Turn this off to keep those copies out of the Inbox, accepting that
        // only the audit trail will then record the send.
        'keep_alias_copy_in_mailbox' => true,
    ],

    'compose' => [
        'max_recipients' => 50,
        'max_subject' => 255,
        'max_body' => 100000,
        'max_attachments' => 10,
        // Kilobytes, per file and in total (the provider receives them base64-encoded, a third larger).
        'max_attachment_kb' => 10240,
        'max_total_attachment_kb' => 15360,
        // Never sent from the Mail Center: programs and scripts a recipient's computer could run.
        'blocked_extensions' => ['exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'cpl', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'psm1', 'jar', 'hta', 'lnk', 'reg', 'sh', 'app', 'dll', 'iso', 'img'],
        // How long a compose form's one-time key is remembered, so a second click or a resubmitted form never sends twice.
        'idempotency_seconds' => 86400,
    ],

    'reading' => [
        // Inline (cid:) pictures of one message embedded in its rendering, each and in total (kilobytes).
        'max_inline_images' => 12,
        'max_inline_image_kb' => 2048,
        'max_inline_total_kb' => 6144,
        // Attachment types that may be previewed in the browser, after their bytes are checked. Everything else downloads.
        'preview_types' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
    ],

    // "Mailbox opened" is written to the audit log at most once per account and mailbox in this window.
    'audit_open_window_seconds' => 1800,

];
