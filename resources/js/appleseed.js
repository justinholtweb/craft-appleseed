/**
 * Appleseed control panel behaviour, for Craft 6.
 *
 * Craft 6 loads this on every control panel page, in <head>, before Vue renders the page, and
 * swaps page content with Inertia visits. So nothing binds to elements directly: clicks and changes
 * are delegated from `document`, and the scan-progress poll looks for its element after each
 * navigation. No jQuery and no `Craft.*` helpers, which Craft 6 doesn't provide; every URL comes
 * from the markup, and every message from the server's JSON.
 */
(function () {
    'use strict';

    function xsrfToken() {
        var match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

        return match ? decodeURIComponent(match[1]) : '';
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(data || {}),
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                if (!response.ok || json.success === false) {
                    var error = new Error(json.message || response.statusText || 'Request failed');
                    throw error;
                }

                return json;
            });
        });
    }

    function say(from, message, isError) {
        var scope = from.closest('.appleseed-screen, .appleseed-entry-panel') || document;
        var flash = scope.querySelector('[data-appleseed-flash]');

        if (!flash || !message) {
            return;
        }

        flash.textContent = message;
        flash.classList.remove('hidden');
        flash.classList.toggle('is-error', !!isError);
    }

    function reloadSoon() {
        setTimeout(function () {
            window.location.reload();
        }, 600);
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;

        if (!target) {
            return;
        }

        // Start a scan: full, changed entries or re-check broken
        var scanButton = target.closest('[data-appleseed-scan]');
        if (scanButton && !scanButton.disabled) {
            scanButton.disabled = true;
            post(scanButton.dataset.url, { type: scanButton.dataset.appleseedScan })
                .then(function (json) {
                    say(scanButton, json.message, false);
                    startPolling(document.querySelector('[data-appleseed-dashboard]'));
                })
                .catch(function (error) {
                    scanButton.disabled = false;
                    say(scanButton, error.message, true);
                });
            return;
        }

        // Scan the chosen sections
        var sectionsButton = target.closest('[data-appleseed-scan-sections]');
        if (sectionsButton && !sectionsButton.disabled) {
            var ids = Array.prototype.map.call(
                document.querySelectorAll('[data-appleseed-section]:checked'),
                function (checkbox) { return checkbox.value; }
            );

            if (!ids.length) {
                say(sectionsButton, sectionsButton.dataset.emptyMessage, true);
                return;
            }

            sectionsButton.disabled = true;
            post(sectionsButton.dataset.url, { sectionIds: ids })
                .then(function (json) {
                    say(sectionsButton, json.message, false);
                    var picker = document.getElementById('appleseed-section-picker');
                    if (picker) {
                        picker.classList.add('hidden');
                    }
                    startPolling(document.querySelector('[data-appleseed-dashboard]'));
                })
                .catch(function (error) {
                    sectionsButton.disabled = false;
                    say(sectionsButton, error.message, true);
                });
            return;
        }

        // Select all / none in the section picker
        var selectButton = target.closest('[data-appleseed-select-sections]');
        if (selectButton) {
            var checked = selectButton.dataset.appleseedSelectSections === 'all';
            document.querySelectorAll('[data-appleseed-section]').forEach(function (checkbox) {
                checkbox.checked = checked;
            });
            return;
        }

        // Show and hide a panel
        var toggle = target.closest('[data-appleseed-toggle]');
        if (toggle) {
            var panel = document.getElementById(toggle.dataset.appleseedToggle);
            if (panel) {
                var hidden = panel.classList.toggle('hidden');
                document.querySelectorAll('[aria-controls="' + panel.id + '"]').forEach(function (control) {
                    control.setAttribute('aria-expanded', hidden ? 'false' : 'true');
                });
            }
            return;
        }

        // Cancel the running scan
        var cancelButton = target.closest('[data-appleseed-cancel]');
        if (cancelButton && !cancelButton.disabled) {
            cancelButton.disabled = true;
            post(cancelButton.dataset.url)
                .then(function (json) {
                    say(cancelButton, json.message, false);
                    reloadSoon();
                })
                .catch(function (error) {
                    cancelButton.disabled = false;
                    say(cancelButton, error.message, true);
                });
            return;
        }

        // Ignore, un-ignore or re-check a link
        var linkButton = target.closest('[data-appleseed-link-action]');
        if (linkButton && !linkButton.disabled) {
            var action = linkButton.dataset.appleseedLinkAction;
            linkButton.disabled = true;
            post(linkButton.dataset.url, { linkId: linkButton.dataset.linkId })
                .then(function (json) {
                    say(linkButton, json.message, false);
                    if (action !== 'rescan') {
                        reloadSoon();
                    }
                })
                .catch(function (error) {
                    linkButton.disabled = false;
                    say(linkButton, error.message, true);
                });
            return;
        }

        // "Check this entry again" in the entry editor's sidebar. It's inside the entry's form,
        // so it must never submit it.
        var entryButton = target.closest('[data-appleseed-entry-scan]');
        if (entryButton && !entryButton.disabled) {
            event.preventDefault();
            entryButton.disabled = true;
            post(entryButton.dataset.url, { entryId: entryButton.dataset.entryId, siteId: entryButton.dataset.siteId })
                .then(function (json) {
                    say(entryButton, json.message, false);
                    entryButton.textContent = entryButton.dataset.doneLabel || entryButton.textContent;
                })
                .catch(function (error) {
                    entryButton.disabled = false;
                    say(entryButton, error.message, true);
                });
        }
    });

    // The dashboard's filters submit as soon as they change.
    document.addEventListener('change', function (event) {
        var select = event.target instanceof Element ? event.target.closest('[data-appleseed-autosubmit]') : null;

        if (select && select.form) {
            select.form.submit();
        }
    });

    // Scan progress -------------------------------------------------------------------------------
    var pollTimer = null;

    function startPolling(dashboard) {
        if (!dashboard || pollTimer) {
            return;
        }

        var progress = dashboard.querySelector('[data-appleseed-progress]');
        var bar = dashboard.querySelector('[data-appleseed-progress-bar]');
        var text = dashboard.querySelector('[data-appleseed-progress-text]');
        var cancel = dashboard.querySelector('[data-appleseed-cancel]');

        if (progress) {
            progress.classList.remove('hidden');
        }
        if (cancel) {
            cancel.classList.remove('hidden');
        }

        pollTimer = setInterval(function () {
            if (!document.body.contains(dashboard)) {
                clearInterval(pollTimer);
                pollTimer = null;
                return;
            }

            fetch(dashboard.dataset.progressUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.running) {
                        if (bar) {
                            bar.value = data.progress;
                        }
                        if (text && data.label) {
                            text.textContent = data.label;
                        }
                        return;
                    }

                    clearInterval(pollTimer);
                    pollTimer = null;
                    if (data.finished) {
                        window.location.reload();
                    }
                })
                .catch(function () {
                    clearInterval(pollTimer);
                    pollTimer = null;
                });
        }, 3000);
    }

    // A dashboard drawn with a scan running starts polling; checked after the first render and
    // after every Inertia visit.
    function init() {
        var dashboard = document.querySelector('[data-appleseed-dashboard][data-running="1"]');
        if (dashboard) {
            startPolling(dashboard);
        }
    }

    var tries = 0;
    var waitForPage = setInterval(function () {
        tries++;
        if (document.querySelector('[data-appleseed-dashboard]') || tries > 20) {
            clearInterval(waitForPage);
            init();
        }
    }, 250);

    document.addEventListener('inertia:navigate', function () {
        setTimeout(init, 250);
    });
})();
