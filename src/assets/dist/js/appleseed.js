(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

    function errorMessage(error, fallback) {
        return (error && error.response && error.response.data && error.response.data.message) || fallback;
    }

    // Scan buttons: full, changed entries, re-check broken
    document.querySelectorAll('.appleseed-run-scan-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var label = btn.textContent;
            btn.disabled = true;
            btn.textContent = Craft.t('appleseed', 'Starting…');

            Craft.sendActionRequest('POST', 'appleseed/dashboard/run-scan', {
                data: { type: btn.dataset.type || 'full' }
            })
                .then(function () {
                    btn.textContent = Craft.t('appleseed', 'Scan Queued');
                    startProgressPolling();
                })
                .catch(function (error) {
                    btn.disabled = false;
                    btn.textContent = label;
                    Craft.cp.displayError(errorMessage(error, Craft.t('appleseed', 'Failed to start scan')));
                });
        });
    });

    // Cancel the running scan
    var cancelBtn = document.getElementById('appleseed-cancel-scan');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            cancelBtn.disabled = true;

            Craft.sendActionRequest('POST', 'appleseed/dashboard/cancel-scan')
                .then(function (response) {
                    Craft.cp.displayNotice(response.data.message);
                    window.location.reload();
                })
                .catch(function (error) {
                    cancelBtn.disabled = false;
                    Craft.cp.displayError(errorMessage(error, Craft.t('appleseed', 'Couldn’t cancel the scan')));
                });
        });
    }

    // Section picker toggle
    var sectionPickerToggle = document.getElementById('appleseed-scan-sections-toggle');
    var sectionPicker = document.getElementById('appleseed-section-picker');
    var sectionPickerCancel = document.getElementById('appleseed-section-picker-cancel');

    if (sectionPickerToggle && sectionPicker) {
        sectionPickerToggle.addEventListener('click', function () {
            sectionPicker.classList.toggle('hidden');
        });

        if (sectionPickerCancel) {
            sectionPickerCancel.addEventListener('click', function () {
                sectionPicker.classList.add('hidden');
            });
        }
    }

    // Select all / Select none
    var selectAll = document.getElementById('appleseed-select-all');
    var selectNone = document.getElementById('appleseed-select-none');

    if (selectAll) {
        selectAll.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.appleseed-section-checkbox').forEach(function (cb) { cb.checked = true; });
        });
    }

    if (selectNone) {
        selectNone.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.appleseed-section-checkbox').forEach(function (cb) { cb.checked = false; });
        });
    }

    // Run Section Scan button
    var runSectionScanBtn = document.getElementById('appleseed-run-section-scan');
    if (runSectionScanBtn) {
        runSectionScanBtn.addEventListener('click', function () {
            var checked = document.querySelectorAll('.appleseed-section-checkbox:checked');
            if (checked.length === 0) {
                Craft.cp.displayError('Please select at least one section');
                return;
            }

            var sectionIds = [];
            checked.forEach(function (cb) { sectionIds.push(cb.value); });

            runSectionScanBtn.disabled = true;
            runSectionScanBtn.textContent = 'Starting...';

            Craft.sendActionRequest('POST', 'appleseed/dashboard/run-scan', {
                data: { sectionIds: sectionIds }
            })
                .then(function () {
                    runSectionScanBtn.textContent = 'Scan Queued';
                    sectionPicker.classList.add('hidden');
                    startProgressPolling();
                })
                .catch(function (error) {
                    runSectionScanBtn.disabled = false;
                    runSectionScanBtn.textContent = 'Scan Selected Sections';
                    Craft.cp.displayError(errorMessage(error, 'Failed to start scan'));
                });
        });
    }

    // Progress polling
    var progressEl = document.getElementById('appleseed-progress');
    var progressFill = document.getElementById('appleseed-progress-fill');
    var progressText = document.getElementById('appleseed-progress-text');
    var pollInterval = null;

    function startProgressPolling() {
        if (progressEl) {
            progressEl.classList.remove('hidden');
        }
        if (cancelBtn) {
            cancelBtn.classList.remove('hidden');
        }
        if (pollInterval) {
            return;
        }

        pollInterval = setInterval(function () {
            Craft.sendActionRequest('GET', 'appleseed/dashboard/scan-progress')
                .then(function (response) {
                    var data = response.data;

                    if (data.running) {
                        if (progressFill) {
                            progressFill.style.width = data.progress + '%';
                        }
                        if (progressText) {
                            progressText.textContent = 'Checked ' + data.totalLinksChecked + ' of ' + data.totalLinksFound + ' links (' + data.progress + '%)';
                        }
                    } else {
                        clearInterval(pollInterval);
                        pollInterval = null;

                        if (progressEl) {
                            progressEl.classList.add('hidden');
                        }

                        // Reload page to show fresh results
                        window.location.reload();
                    }
                })
                .catch(function () {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    if (progressEl) {
                        progressEl.classList.add('hidden');
                    }
                });
        }, 3000);
    }

    // Ignore link buttons
    document.querySelectorAll('.appleseed-ignore-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var linkId = btn.dataset.linkId;
            btn.disabled = true;

            Craft.sendActionRequest('POST', 'appleseed/dashboard/ignore-link', {
                data: { linkId: linkId }
            })
                .then(function () {
                    // Remove row from table or reload
                    var row = btn.closest('tr');
                    if (row) {
                        row.style.opacity = '0.3';
                        setTimeout(function () { row.remove(); }, 300);
                    } else {
                        window.location.reload();
                    }
                    Craft.cp.displayNotice('Link ignored');
                })
                .catch(function () {
                    btn.disabled = false;
                    Craft.cp.displayError('Failed to ignore link');
                });
        });
    });

    // Un-ignore link buttons
    document.querySelectorAll('.appleseed-unignore-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var linkId = btn.dataset.linkId;
            btn.disabled = true;

            Craft.sendActionRequest('POST', 'appleseed/dashboard/unignore-link', {
                data: { linkId: linkId }
            })
                .then(function () {
                    window.location.reload();
                    Craft.cp.displayNotice('Link un-ignored');
                })
                .catch(function () {
                    btn.disabled = false;
                    Craft.cp.displayError('Failed to un-ignore link');
                });
        });
    });

    // Rescan link buttons
    document.querySelectorAll('.appleseed-rescan-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var linkId = btn.dataset.linkId;
            btn.disabled = true;
            btn.textContent = '...';

            Craft.sendActionRequest('POST', 'appleseed/dashboard/rescan-link', {
                data: { linkId: linkId }
            })
                .then(function () {
                    Craft.cp.displayNotice('Re-check queued');
                    btn.textContent = 'Queued';
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = '↻';
                    Craft.cp.displayError('Failed to queue re-check');
                });
        });
    });

    // The filters submit on change. Wired here rather than in an inline onchange, so the markup
    // carries no script and the selects are Craft's own.
    document.querySelectorAll('select.appleseed-autosubmit, .appleseed-autosubmit select').forEach(function (select) {
        if (select.form) {
            select.addEventListener('change', function () {
                select.form.submit();
            });
        }
    });

    // Auto-start polling if a scan is already running
    var scanActive = document.getElementById('appleseed-scan-active');
    if (progressEl && scanActive && scanActive.dataset.running === '1') {
        startProgressPolling();
    }

    }); // end DOMContentLoaded

    // "Check this entry again" in the entry editor's sidebar. Delegated, because the sidebar can
    // arrive after the page has loaded (element editor slideouts).
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('.appleseed-entry-scan-btn') : null;
        if (!btn || btn.disabled) {
            return;
        }

        btn.disabled = true;

        Craft.sendActionRequest('POST', 'appleseed/dashboard/scan-entry', {
            data: { entryId: btn.dataset.entryId, siteId: btn.dataset.siteId }
        })
            .then(function (response) {
                Craft.cp.displayNotice(response.data.message);
                btn.textContent = Craft.t('appleseed', 'Queued');
            })
            .catch(function (error) {
                btn.disabled = false;
                var message = error && error.response && error.response.data && error.response.data.message;
                Craft.cp.displayError(message || Craft.t('appleseed', 'Couldn’t queue the entry scan'));
            });
    });
})();
