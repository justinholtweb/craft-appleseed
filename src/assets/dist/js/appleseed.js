(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

    // Run Full Scan button
    var runScanBtn = document.getElementById('appleseed-run-scan');
    if (runScanBtn) {
        runScanBtn.addEventListener('click', function () {
            runScanBtn.disabled = true;
            runScanBtn.textContent = 'Starting...';

            Craft.sendActionRequest('POST', 'appleseed/dashboard/run-scan')
                .then(function () {
                    runScanBtn.textContent = 'Scan Queued';
                    startProgressPolling();
                })
                .catch(function (error) {
                    runScanBtn.disabled = false;
                    runScanBtn.textContent = 'Run Full Scan';
                    Craft.cp.displayError('Failed to start scan');
                    console.error('Appleseed scan error:', error);
                });
        });
    }

    // Section picker toggle
    var sectionPickerToggle = document.getElementById('appleseed-scan-sections-toggle');
    var sectionPicker = document.getElementById('appleseed-section-picker');
    var sectionPickerCancel = document.getElementById('appleseed-section-picker-cancel');

    if (sectionPickerToggle && sectionPicker) {
        sectionPickerToggle.addEventListener('click', function () {
            sectionPicker.style.display = sectionPicker.style.display === 'none' ? 'block' : 'none';
        });

        if (sectionPickerCancel) {
            sectionPickerCancel.addEventListener('click', function () {
                sectionPicker.style.display = 'none';
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
                    sectionPicker.style.display = 'none';
                    startProgressPolling();
                })
                .catch(function (error) {
                    runSectionScanBtn.disabled = false;
                    runSectionScanBtn.textContent = 'Scan Selected Sections';
                    Craft.cp.displayError('Failed to start scan');
                    console.error('Appleseed scan error:', error);
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
            progressEl.style.display = 'block';
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
                            progressEl.style.display = 'none';
                        }

                        // Reload page to show fresh results
                        window.location.reload();
                    }
                })
                .catch(function () {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    if (progressEl) {
                        progressEl.style.display = 'none';
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

    // Auto-start polling if a scan is already running
    var scanActive = document.getElementById('appleseed-scan-active');
    if (progressEl && scanActive && scanActive.dataset.running === '1') {
        startProgressPolling();
    }

    }); // end DOMContentLoaded
})();
