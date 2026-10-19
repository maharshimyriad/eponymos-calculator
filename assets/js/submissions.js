/* global jQuery, EponymosAdmin */
;(function ($) {
    'use strict';

    var config = window.EponymosAdmin;
    if (!config) return;

    var root = $('.eponymos-admin');
    var page = 1;

    // ── API ─────────────────────────────────────────────────────────────────

    function api(path, method, body) {
        return fetch(config.endpoint + path, {
            method: method || 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce,
            },
            body: body ? JSON.stringify(body) : undefined,
        }).then(function (r) {
            return r.json().then(function (x) {
                if (!r.ok) throw x;
                return x;
            });
        });
    }

    function esc(value) {
        return $('<span>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function pretty(value) {
        if (value === null || value === undefined) return '';
        if (typeof value === 'object') return esc(JSON.stringify(value, null, 2));
        return esc(value);
    }

    function notice(message, isError) {
        var n = root.find('[data-submission-notice]');
        n.text(message).toggleClass('notice-error', !!isError);
    }

    // ── Load & render list ──────────────────────────────────────────────────

    function load() {
        var search        = encodeURIComponent(root.find('[data-submission-search]').val() || '');
        var status        = encodeURIComponent(root.find('[data-submission-status]').val() || '');
        var questionnaire = encodeURIComponent(root.find('[data-submission-questionnaire]').val() || '');

        root.find('[data-submission-list]').html('<p>Loading submissions\u2026</p>');
        api(
            '/submissions?page=' + page +
            '&per_page=20' +
            '&search=' + search +
            '&status=' + status +
            '&questionnaire_id=' + questionnaire
        )
            .then(renderList)
            .catch(function () { notice('Submissions could not be loaded.', true); });
    }

    function loadEmailLogs() {
        var panel = root.find('[data-email-log-panel]');
        panel.html('<p>Loading email delivery logs\u2026</p>');
        api('/email-logs')
            .then(renderEmailLogs)
            .catch(function () { notice('Email delivery logs could not be loaded.', true); });
    }

    function loadFlowLogs() {
        var panel = root.find('[data-flow-log-panel]');
        panel.html('<p>Loading technical flow logs\u2026</p>');
        api('/flow-logs')
            .then(renderFlowLogs)
            .catch(function () { notice('Technical flow logs could not be loaded.', true); });
    }

    function renderFlowLogs(data) {
        var items = data.items || [];
        var h = '<div class="email-log-heading"><h2>Technical flow log</h2><p>Use request IDs and status details to investigate submission failures. Answer values are not stored here.</p><button type="button" class="button button-link-delete" data-delete-flow-logs>Delete all technical logs</button></div>' +
            '<table class="widefat striped"><thead><tr><th>Time</th><th>Status</th><th>Event</th><th>Route</th><th>Message</th><th>Request ID</th><th>Context</th></tr></thead><tbody>';
        if (!items.length) h += '<tr><td colspan="7">No technical flow events have been recorded.</td></tr>';
        items.forEach(function (item) {
            var context = item.context || '{}';
            try { context = JSON.stringify(JSON.parse(context), null, 2); } catch (e) { }
            h += '<tr><td>' + esc(item.created_at) + '</td><td><strong class="email-log-status--' + esc(item.status === 'completed' ? 'accepted' : 'failed') + '">' + esc(item.status) + '</strong></td><td>' + esc(item.event) + '</td><td>' + esc(item.route) + '</td><td>' + esc(item.message) + '</td><td><code>' + esc(item.request_id) + '</code></td><td><pre>' + esc(context) + '</pre></td></tr>';
        });
        h += '</tbody></table>';
        root.find('[data-flow-log-panel]').html(h);
    }

    function renderEmailLogs(data) {
        var items = data.items || [];
        var h = '<div class="email-log-heading"><h2>Email delivery log</h2><p>Use the plain-language result for a quick check, or review the technical details when troubleshooting.</p><button type="button" class="button button-link-delete" data-delete-email-logs>Delete all email logs</button></div>' +
            '<table class="widefat striped"><thead><tr>' +
            '<th>Result</th><th>Submission</th><th>Questionnaire</th><th>Technical details</th>' +
            '</tr></thead><tbody>';

        if (!items.length) {
            h += '<tr><td colspan="4">No email delivery attempts have been recorded.</td></tr>';
        }

        items.forEach(function (item) {
            var accepted = item.status === 'accepted';
            var result = accepted
                ? '<strong class="email-log-status email-log-status--accepted">Email accepted for sending</strong><p>The notification was handed to WordPress successfully.</p>'
                : '<strong class="email-log-status email-log-status--failed">Email could not be sent</strong><p>WordPress did not accept the notification for sending.</p>';
            var technical = '<dl class="email-log-technical">' +
                '<dt>Status</dt><dd>' + esc(item.status) + '</dd>' +
                '<dt>Recipient</dt><dd>' + esc(item.recipient) + '</dd>' +
                '<dt>Submitter</dt><dd>' + esc(item.submitter_email || '') + '</dd>' +
                '<dt>Submission ID</dt><dd>#' + esc(item.submission_id) + '</dd>' +
                '<dt>Recorded</dt><dd>' + esc(item.created_at) + '</dd>' +
                '</dl>';
            h += '<tr><td>' + result + '</td><td>#' + esc(item.submission_id) + '</td><td>' + esc(item.questionnaire_name || 'Unavailable') + '</td><td>' + technical + '</td></tr>';
        });

        h += '</tbody></table>';
        root.find('[data-email-log-panel]').html(h);
    }

    function renderList(data) {
        console.log('Preview nonce:', config.previewNonce);
        var h = '<div class="submission-bulk-actions"><label><input type="checkbox" data-submission-select-all> Select all on this page</label> <button class="button button-secondary" data-delete-selected disabled>Delete selected</button> <button class="button-link-delete" data-delete-all>Delete all submissions</button></div>' +
            '<table class="widefat striped"><thead><tr>' +
            '<th><input type="checkbox" data-submission-select-all aria-label="Select all submissions on this page"></th><th>Submission ID</th><th>Email</th><th>Questionnaire</th><th>Status</th><th>Submitted</th><th>Estimate</th><th></th>' +
            '</tr></thead><tbody>';

        if (!data.items.length) {
            h += '<tr><td colspan="8">No submissions found.</td></tr>';
        }

        data.items.forEach(function (item) {
            var isNetworked = item.pricing_result && item.pricing_result.plan === 'networked';
            var estimate = isNetworked
                ? 'Contact us for pricing'
                : item.pricing_result && item.pricing_result.monthly_estimate !== null && item.pricing_result.monthly_estimate !== undefined
                    ? '$' + Number(item.pricing_result.monthly_estimate).toLocaleString()
                    : '\u2014';
            h +=
                '<tr>' +
                '<td><input type="checkbox" data-submission-select value="' + item.id + '"></td>' +
                '<td>#' + esc(item.id) + '</td>' +
                '<td><button class="button-link" data-open-submission="' + item.id + '">' + esc(item.email) + '</button></td>' +
                '<td>' + esc(item.questionnaire_name || 'Unavailable') + '</td>' +
                '<td>' + esc(item.status) + '</td>' +
                '<td>' + esc(item.created_at) + '</td>' +
                '<td>' + estimate + '</td>' +
                '<td style="white-space:nowrap">' +
                    '<a class="button button-small" href="' + esc(config.restBase + 'eponymos/v1/report/' + item.id + '/preview?preview_nonce=' + config.previewNonce) + '" target="_blank" rel="noopener">Preview report</a> ' +
                    '<button class="button-link-delete" data-delete-submission="' + item.id + '">Delete</button>' +
                '</td>' +
                '</tr>';
        });

        h += '</tbody></table>';

        if (data.pages > 1) {
            h +=
                '<p class="tablenav">' +
                '<button class="button" data-submission-page="prev"' + (data.page <= 1 ? ' disabled' : '') + '>Previous</button>' +
                ' <span>Page ' + data.page + ' of ' + data.pages + '</span> ' +
                '<button class="button" data-submission-page="next"' + (data.page >= data.pages ? ' disabled' : '') + '>Next</button>' +
                '</p>';
        }

        root.find('[data-submission-list]').html(h);
        updateBulkState();
    }

    function updateBulkState() {
        var selected = root.find('[data-submission-select]:checked').length;
        root.find('[data-delete-selected]').prop('disabled', !selected);
    }

    // ── Submission detail ───────────────────────────────────────────────────

    function renderDetail(data) {
        var h =
            '<p><button class="button" data-close-submission>\u2190 Back to submissions</button></p>' +
            '<div class="submission-detail">' +
            '<h2>Submission #' + esc(data.id) + '</h2>' +
            '<section><h3>Information</h3><dl>' +
            '<dt>Email</dt><dd>' + esc(data.email) + '</dd>' +
            '<dt>Questionnaire</dt><dd>' + esc(data.questionnaire_name || 'Unavailable') + '</dd>' +
            '<dt>Date / time</dt><dd>' + esc(data.created_at) + '</dd>' +
            '<dt>Status</dt><dd>' + esc(data.status) + '</dd>' +
            '</dl></section>';

        h += '<section><h3>Answers</h3>';
        if (!data.stages || !data.stages.length) {
            h += '<p>No stored answers.</p>';
        } else {
            data.stages.forEach(function (stage) {
                h += '<h4>' + esc(stage.title) + '</h4><dl>';
                stage.answers.forEach(function (answer) {
                    h += '<dt>' + esc(answer.question) + '</dt><dd>' + esc(answer.answer) + '</dd>';
                });
                h += '</dl>';
            });
        }
        h += '</section>';

        h +=
            '<section><h3>Pricing estimate</h3><pre>' + pretty(data.pricing_result) + '</pre></section>' +
            '<section><h3>Calculation outputs</h3><pre>' + pretty(data.calculation_outputs) + '</pre>' +
            '<p><small>Methodology version: ' + esc(data.methodology_version) + '</small></p></section>';

        if (data.report && data.report.status === 'available' && data.report.data) {
            h += '<section><h3>Report</h3>';
            var sections = data.report.data.sections || [];
            sections.forEach(function (sec) {
                h += '<h4>' + esc(sec.title) + '</h4><table class="widefat"><tbody>';
                (sec.rows || []).forEach(function (row) {
                    h += '<tr><td>' + esc(row.label) + '</td><td><strong>' + esc(row.value) + '</strong></td></tr>';
                });
                h += '</tbody></table>';
            });
            var qualitative = data.report.data.qualitative || [];
            if (qualitative.length) {
                h += '<h4>Insights</h4><ul>';
                qualitative.forEach(function (q) { h += '<li>' + esc(q.message) + '</li>'; });
                h += '</ul>';
            }
            h += '</section>';
        }

        h += '</div>';

        root.find('[data-submission-detail]').html(h).prop('hidden', false);
        root.find('[data-submission-list], .submissions-toolbar, [data-submission-notice]').prop('hidden', true);
    }

    // ── Events ──────────────────────────────────────────────────────────────

    root.on('click', '[data-submission-search-button]', function () { page = 1; load(); });

    root.on('click', '[data-submission-tab]', function () {
        var tab = $(this).data('submission-tab');
        root.find('[data-submission-tab]').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        root.find('[data-submission-detail]').prop('hidden', true);
        root.find('[data-submission-list], .submissions-toolbar, [data-submission-notice]').prop('hidden', tab !== 'submissions');
        root.find('[data-email-log-panel]').prop('hidden', tab !== 'email-logs');
        root.find('[data-flow-log-panel]').prop('hidden', tab !== 'flow-logs');
        if (tab === 'email-logs') loadEmailLogs();
        if (tab === 'flow-logs') loadFlowLogs();
    });

    root.on('click', '[data-delete-email-logs]', function () {
        if (!confirm('Delete all email delivery logs? This cannot be undone.')) return;
        api('/email-logs', 'DELETE', {})
            .then(function (data) { notice(data.deleted + ' email logs deleted.'); loadEmailLogs(); })
            .catch(function () { notice('Email logs could not be deleted.', true); });
    });

    root.on('click', '[data-delete-flow-logs]', function () {
        if (!confirm('Delete all technical flow logs? This cannot be undone.')) return;
        api('/flow-logs', 'DELETE', {})
            .then(function (data) { notice(data.deleted + ' technical logs deleted.'); loadFlowLogs(); })
            .catch(function () { notice('Technical flow logs could not be deleted.', true); });
    });

    root.on('change', '[data-submission-questionnaire], [data-submission-status]', function () { page = 1; load(); });

    root.on('keydown', '[data-submission-search]', function (e) {
        if (e.key === 'Enter') { page = 1; load(); }
    });

    root.on('click', '[data-submission-page]', function () {
        page += $(this).data('submission-page') === 'next' ? 1 : -1;
        load();
    });

    root.on('click', '[data-open-submission]', function () {
        api('/submissions/' + $(this).data('open-submission'))
            .then(renderDetail)
            .catch(function () { notice('Submission could not be loaded.', true); });
    });

    root.on('click', '[data-close-submission]', function () {
        root.find('[data-submission-detail]').prop('hidden', true);
        root.find('[data-submission-list], .submissions-toolbar, [data-submission-notice]').prop('hidden', false);
        root.find('[data-submission-tab]').removeClass('nav-tab-active').first().addClass('nav-tab-active');
        load();
    });

    root.on('change', '[data-submission-select-all]', function () {
        root.find('[data-submission-select]').prop('checked', $(this).prop('checked'));
        updateBulkState();
    });

    root.on('change', '[data-submission-select]', updateBulkState);

    root.on('click', '[data-delete-selected]', function () {
        var ids = root.find('[data-submission-select]:checked').map(function () { return Number($(this).val()); }).get();
        if (!ids.length || !confirm('Delete the selected submissions and their stored answers? This cannot be undone.')) return;
        api('/submissions', 'DELETE', { ids: ids }).then(function (x) { notice(x.deleted + ' submissions deleted.'); load(); }).catch(function () { notice('Selected submissions could not be deleted.', true); });
    });

    root.on('click', '[data-delete-all]', function () {
        if (!confirm('Delete all submissions and their stored answers? This cannot be undone.')) return;
        api('/submissions', 'DELETE', { all: true }).then(function (x) { notice(x.deleted + ' submissions deleted.'); page = 1; load(); }).catch(function () { notice('Submissions could not be deleted.', true); });
    });

    root.on('click', '[data-delete-submission]', function () {
        var id = $(this).data('delete-submission');
        if (!confirm('Delete this submission and its stored answers? This cannot be undone.')) return;
        api('/submissions/' + id, 'DELETE', {})
            .then(function () { notice('Submission deleted.'); load(); })
            .catch(function () { notice('Submission could not be deleted.', true); });
    });

    // Auto-load on page ready
    $(function () { load(); });

})(jQuery);
