/*
 * Mail Center behaviour (docs/MAIL_CENTER.md). Progressive enhancement only: every page and form works without it, and
 * the server enforces every rule itself.
 *
 *  - Unread badge in the top bar: asks /admin/mail/unread once after the page loads and then every data-mail-poll
 *    seconds, ONLY while the tab is visible (no background polling, no sound).
 *  - Message frames: the sanitized message is shown in a sandboxed frame that runs no script of its own; this sizes the
 *    frame to its content so the page, not the frame, scrolls.
 *  - Sending: a page-level "Sending…" state while a compose, reply or forward form is on its way (the double-submit guard
 *    itself is provatferi-form-guard.js, and the server sends a form at most once anyway).
 *  - "Move to Trash" asks first.
 *  - Access grid: replying, forwarding and message state include reading — ticking one ticks Read, unticking Read unticks
 *    them (the server applies the same rule).
 *
 * Nothing here reads, stores or sends message content; it holds no credential and talks only to the ERP's own routes.
 */
(function () {
    'use strict';

    function formatCount(n) {
        try {
            return new Intl.NumberFormat(document.documentElement.lang || undefined).format(n);
        } catch (e) {
            return String(n);
        }
    }

    function unreadBadge() {
        var link = document.querySelector('[data-mail-unread-url]');
        if (!link || !window.fetch) {
            return;
        }
        var bubble = link.querySelector('[data-mail-unread-count]');
        var url = link.getAttribute('data-mail-unread-url');
        var every = Math.max(30, parseInt(link.getAttribute('data-mail-poll'), 10) || 90) * 1000;
        var template = link.getAttribute('data-mail-unread-label') || '';
        var baseLabel = link.getAttribute('aria-label') || '';
        var busy = false;

        function show(total) {
            if (!bubble) {
                return;
            }
            bubble.textContent = total > 99 ? formatCount(99) + '+' : formatCount(total);
            bubble.hidden = total <= 0;
            var text = total > 0 ? baseLabel + ' — ' + template.replace(':count', formatCount(total)) : baseLabel;
            link.setAttribute('aria-label', text);
            link.setAttribute('title', text);
        }

        function poll() {
            if (busy || document.visibilityState !== 'visible') {
                return;
            }
            busy = true;
            fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (data) {
                    if (data && typeof data.total === 'number') {
                        show(data.total);
                    }
                })
                .catch(function () { /* the badge simply stays as it was */ })
                .then(function () { busy = false; });
        }

        poll();
        window.setInterval(poll, every);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                poll();
            }
        });
    }

    function messageFrames() {
        Array.prototype.forEach.call(document.querySelectorAll('iframe.pf-mail-frame'), function (frame) {
            // The CONTENT's height (the body), never the document's: a document is at least as tall as its frame, so
            // measuring it would grow the frame a little on every resize, forever.
            function fit() {
                try {
                    var body = frame.contentDocument && frame.contentDocument.body;
                    if (!body) {
                        return;
                    }
                    var height = Math.min(20000, Math.max(120, Math.ceil(Math.max(body.getBoundingClientRect().height, body.scrollHeight)) + 2));
                    var current = parseInt(frame.style.height, 10) || 0;
                    if (Math.abs(height - current) > 2) {
                        frame.style.height = height + 'px';
                    }
                } catch (e) { /* a frame we cannot measure keeps its CSS height and scrolls itself */ }
            }

            function watch() {
                fit();
                try {
                    var doc = frame.contentDocument;
                    Array.prototype.forEach.call(doc.images || [], function (img) {
                        if (!img.complete) {
                            img.addEventListener('load', fit);
                        }
                    });
                    if (window.ResizeObserver && doc.body) {
                        new ResizeObserver(fit).observe(doc.body);
                    }
                } catch (e) { /* see fit() */ }
            }

            frame.addEventListener('load', watch);
            if (frame.contentDocument && frame.contentDocument.readyState === 'complete') {
                watch();
            }
        });
    }

    function sendingState() {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-mail-send-form]'), function (form) {
            form.addEventListener('submit', function () {
                if (document.querySelector('.pf-mail-sending')) {
                    return;
                }
                var overlay = document.createElement('div');
                overlay.className = 'pf-mail-sending';
                overlay.setAttribute('role', 'status');
                overlay.setAttribute('aria-live', 'polite');
                var box = document.createElement('div');
                box.className = 'pf-mail-sending-box';
                var spinner = document.createElement('span');
                spinner.className = 'spinner-border text-primary';
                spinner.setAttribute('aria-hidden', 'true');
                var text = document.createElement('span');
                text.textContent = form.getAttribute('data-sending-label') || '';
                box.appendChild(spinner);
                box.appendChild(text);
                overlay.appendChild(box);
                document.body.appendChild(overlay);
            });
        });
        // A page restored from the back/forward cache must not keep the overlay.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                Array.prototype.forEach.call(document.querySelectorAll('.pf-mail-sending'), function (node) { node.remove(); });
            }
        });
    }

    function confirmations() {
        document.addEventListener('submit', function (event) {
            var form = event.target.closest('form[data-mail-confirm]');
            if (form && !window.confirm(form.getAttribute('data-mail-confirm'))) {
                event.preventDefault();
            }
        }, true);
    }

    function accessGrid() {
        var NEEDS_READ = ['reply', 'forward', 'manage_state'];
        document.addEventListener('change', function (event) {
            var box = event.target;
            if (!box.matches || !box.matches('form[data-mail-access-form] input[type="checkbox"][data-capability]')) {
                return;
            }
            var row = box.closest('tr');
            if (!row) {
                return;
            }
            var capability = box.getAttribute('data-capability');
            if (box.checked && NEEDS_READ.indexOf(capability) !== -1) {
                var read = row.querySelector('input[data-capability="read"]');
                if (read) {
                    read.checked = true;
                }
            }
            if (!box.checked && capability === 'read') {
                NEEDS_READ.forEach(function (name) {
                    var other = row.querySelector('input[data-capability="' + name + '"]');
                    if (other) {
                        other.checked = false;
                    }
                });
            }
        });
    }

    function start() {
        unreadBadge();
        messageFrames();
        sendingState();
        confirmations();
        accessGrid();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
