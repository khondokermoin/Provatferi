/*
 * Copy / download / print helpers for the two-step verification pages (docs/MFA_SECURITY.md). Plain JS, no inline
 * handlers. Everything here happens in the browser only: nothing is sent anywhere, nothing is stored (no localStorage),
 * and a downloaded file is made from what is already on the page.
 *
 *   <button data-copy-target="id" data-copied-label="Copied">      copies the text of #id (a key without its spaces; a list
 *                                                                  of codes one per line)
 *   <button data-download-target="id" data-download-name="x.txt">  saves the codes of #id as a text file
 *   <button data-print-page>                                       opens the print dialog
 */
(function () {
    'use strict';

    function textOf(id) {
        var el = document.getElementById(id);
        if (!el) {
            return '';
        }
        var items = el.querySelectorAll('code');
        if (items.length > 1 || el.tagName === 'OL' || el.tagName === 'UL') {
            return Array.prototype.map.call(items, function (c) { return c.textContent.trim(); }).join('\n');
        }
        return el.textContent.replace(/\s+/g, '');
    }

    document.addEventListener('click', function (event) {
        var copy = event.target.closest('[data-copy-target]');
        if (copy) {
            var text = textOf(copy.getAttribute('data-copy-target'));
            if (text && navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () {
                    var label = copy.querySelector('span');
                    var copied = copy.getAttribute('data-copied-label');
                    if (label && copied) {
                        var before = label.textContent;
                        label.textContent = copied;
                        setTimeout(function () { label.textContent = before; }, 2000);
                    }
                });
            }
            return;
        }

        var download = event.target.closest('[data-download-target]');
        if (download) {
            var content = textOf(download.getAttribute('data-download-target'));
            if (!content) {
                return;
            }
            var blob = new Blob(['Provatferi ERP — recovery codes\n\n' + content + '\n'], { type: 'text/plain;charset=utf-8' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = download.getAttribute('data-download-name') || 'recovery-codes.txt';
            document.body.appendChild(link);
            link.click();
            setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 0);
            return;
        }

        if (event.target.closest('[data-print-page]')) {
            window.print();
        }
    });
})();
