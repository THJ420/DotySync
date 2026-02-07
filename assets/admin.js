jQuery(document).ready(function ($) {
    var $btn = $('#dotysync-sync-btn');
    var $stopBtn = $('#dotysync-stop-btn');
    var $log = $('#dotysync-logs');
    var isSyncing = false;
    var maxLogs = 1000;

    // Initially disabled
    if ($stopBtn.length) {
        $stopBtn.prop('disabled', true);
    }

    function appendLog(message) {
        if (!$log.length) return;
        var currentVal = $log.val();
        var lines = currentVal.split('\n');
        if (lines.length >= maxLogs) {
            lines.shift(); // Remove oldest
        }
        lines.push(message);
        $log.val(lines.join('\n'));
        $log.scrollTop($log[0].scrollHeight);
    }

    function runBatch(offset) {
        if (!isSyncing) {
            appendLog('Sync Stopped by User.');
            $btn.prop('disabled', false).text('Sync Now');
            $stopBtn.prop('disabled', true);
            return;
        }

        $.post(dotysyncParams.ajaxurl, {
            action: 'dotysync_manual_sync',
            nonce: dotysyncParams.nonce,
            offset: offset
        }, function (response) {
            if (!isSyncing) return; // Stop if cancelled during request

            if (response.success) {
                var data = response.data;
                appendLog('Batch completed: Synced ' + data.synced_count + ' products.');

                if (data.log_messages && data.log_messages.length > 0) {
                    data.log_messages.forEach(function (msg) {
                        appendLog(' - ' + msg);
                    });
                }

                if (data.has_more) {
                    appendLog('Fetching next batch (Offset: ' + data.next_offset + ')...');
                    runBatch(data.next_offset);
                } else {
                    appendLog('Sync Complete!');
                    $btn.prop('disabled', false).text('Sync Now');
                    $stopBtn.prop('disabled', true);
                    isSyncing = false;
                }
            } else {
                appendLog('Error: ' + response.data);
                $btn.prop('disabled', false).text('Sync Now');
                $stopBtn.prop('disabled', true);
                isSyncing = false;
            }
        }).fail(function () {
            appendLog('AJAX Error or Timeout.');
            $btn.prop('disabled', false).text('Sync Now');
            $stopBtn.prop('disabled', true);
            isSyncing = false;
        });
    }

    $btn.on('click', function (e) {
        e.preventDefault();
        if (isSyncing) return;

        // if (!confirm('Are you sure you want to start the sync?')) return; // Optional confirmation

        isSyncing = true;
        $btn.prop('disabled', true).text('Syncing...');

        // Force enable the stop button
        if ($stopBtn.length) {
            $stopBtn.prop('disabled', false).text('Stop Sync');
        }

        $log.val('Starting Sync...\n');

        runBatch(0);
    });

    $stopBtn.on('click', function (e) {
        e.preventDefault();
        if (isSyncing) {
            isSyncing = false;
            appendLog('Stopping sync...');
            $(this).text('Stopping...');
        }
    });

});
