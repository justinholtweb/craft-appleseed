(function () {
    'use strict';

    // Run Scan button
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
                    runScanBtn.textContent = 'Run Scan';
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
    if (progressEl && document.body.dataset.runningScan === '1') {
        startProgressPolling();
    }
})();
