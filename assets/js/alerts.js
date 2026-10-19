/* =====================================================================
   PoultryGuard - alert history page.
   ===================================================================== */

(function () {
    'use strict';

    const canManage = (window.PG_PAGE || {}).canManage === true;
    let page = 1;

    function filters() {
        return {
            device_id:  document.getElementById('fDevice').value,
            severity:   document.getElementById('fSeverity').value,
            status:     document.getElementById('fStatus').value,
            alert_type: document.getElementById('fType').value,
            from:       document.getElementById('fFrom').value,
            to:         document.getElementById('fTo').value,
            page:       page,
            per_page:   25
        };
    }

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------
    function paint(data) {
        PG.setText('sumTotal', PG.fmt.number(data.summary.total));
        PG.setText('sumUnread', PG.fmt.number(data.summary.unread));
        PG.setText('sumCritical', PG.fmt.number(data.summary.critical));
        PG.setText('sumResolved', PG.fmt.number(data.summary.resolved));

        const p = data.pagination;
        PG.setText('pageInfo', p.total === 0
            ? 'No alerts match these filters'
            : 'Showing page ' + p.page + ' of ' + p.pages + ' (' + PG.fmt.number(p.total) + ' alerts)');

        paintRows(data.alerts);
        paintPager(p);
    }

    function paintRows(rows) {
        const body = document.getElementById('alertBody');
        const cols = canManage ? 7 : 6;

        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="' + cols + '" class="text-center pg-muted py-4">' +
                'No alerts match the selected filters.</td></tr>';
            return;
        }

        body.innerHTML = rows.map(function (a) {
            const sevBadge = a.severity === 'CRITICAL' ? 'bg-danger'
                : a.severity === 'WARNING' ? 'bg-warning' : 'bg-info';
            const statusBadge = a.status === 'unread' ? 'bg-warning'
                : a.status === 'resolved' ? 'bg-success' : 'bg-secondary';

            let actions = '';
            if (canManage) {
                actions = '<td class="pg-no-print text-nowrap">';
                if (a.status === 'unread') {
                    actions += '<button class="btn btn-sm btn-light me-1" data-act="read" data-id="' +
                        a.alert_id + '">Mark read</button>';
                }
                if (a.status !== 'resolved') {
                    actions += '<button class="btn btn-sm btn-outline-success" data-act="resolved" data-id="' +
                        a.alert_id + '">Resolve</button>';
                } else {
                    actions += '<button class="btn btn-sm btn-light" data-act="unread" data-id="' +
                        a.alert_id + '">Reopen</button>';
                }
                actions += '</td>';
            }

            // Unread is tinted amber, matching the dashboard list. Green is
            // the brand colour here and always reads as "resolved", so it
            // cannot double as "still needs a look".
            return '<tr' + (a.status === 'unread' ? ' class="pg-row-unread"' : '') + '>' +
                '<td class="text-nowrap">' + PG.escapeHtml(PG.fmt.datetime(a.created_at)) +
                    '<div class="pg-muted" style="font-size:.72rem">' + PG.escapeHtml(a.ago) + '</div></td>' +
                '<td><span class="badge ' + sevBadge + '">' + PG.escapeHtml(a.severity) + '</span></td>' +
                '<td>' + PG.escapeHtml(PG.fmt.label(a.alert_type)) + '</td>' +
                '<td>' + PG.escapeHtml(a.message) + '</td>' +
                '<td class="pg-muted">' + PG.escapeHtml(a.device_name) + '</td>' +
                '<td><span class="badge ' + statusBadge + '">' + PG.escapeHtml(a.status) + '</span></td>' +
                actions +
            '</tr>';
        }).join('');
    }

    function paintPager(p) {
        const pager = document.getElementById('pager');
        if (p.pages <= 1) {
            pager.innerHTML = '';
            return;
        }

        // Show a window of pages around the current one rather than all of them.
        const items = [];
        const push = function (n, label, disabled, active) {
            items.push('<li class="page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '') +
                '"><button class="page-link" data-page="' + n + '">' + label + '</button></li>');
        };

        push(p.page - 1, '&laquo;', p.page === 1, false);
        const start = Math.max(1, p.page - 2);
        const end = Math.min(p.pages, start + 4);
        for (let i = start; i <= end; i++) push(i, String(i), false, i === p.page);
        push(p.page + 1, '&raquo;', p.page === p.pages, false);

        pager.innerHTML = items.join('');
    }

    // -----------------------------------------------------------------
    // Load & actions
    // -----------------------------------------------------------------
    async function load() {
        const data = await PG.api('api/alerts/get.php', { params: filters() });
        paint(data);
    }

    function refresh() {
        load().catch(function (e) { PG.toast(e.message, 'danger'); });
    }

    async function setStatus(alertId, status) {
        try {
            await PG.api('api/alerts/read.php', {
                method: 'POST',
                body: { alert_id: alertId, status: status }
            });
            refresh();
        } catch (e) {
            PG.toast(e.message, 'danger');
        }
    }

    async function bulk(action, label, variant) {
        const device = document.getElementById('fDevice').value;
        const scope = device === 'all' ? 'every device' : 'the selected device';

        const ok = await PG.confirm({
            title: label,
            message: label + ' for ' + scope + '?',
            detail: action === 'resolve_all'
                ? 'Resolved alerts stay in the history but no longer count as open.'
                : 'This clears the unread badge; the alerts remain in the history.',
            confirmText: label,
            variant: variant
        });
        if (!ok) return;

        try {
            const res = await PG.api('api/alerts/read.php', {
                method: 'POST',
                body: { action: action, device_id: device }
            });
            PG.toast(res.affected + ' alert(s) updated.', 'success');
            refresh();
        } catch (e) {
            PG.toast(e.message, 'danger');
        }
    }

    // -----------------------------------------------------------------
    // Wire up
    // -----------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        refresh();

        document.getElementById('filterForm').addEventListener('submit', function (e) {
            e.preventDefault();
            page = 1;
            refresh();
        });

        document.getElementById('resetFilters').addEventListener('click', function () {
            document.getElementById('filterForm').reset();
            page = 1;
            refresh();
        });

        // Row action buttons (delegated: the table is re-rendered on every load)
        document.getElementById('alertBody').addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-act]');
            if (!btn) return;
            setStatus(parseInt(btn.dataset.id, 10), btn.dataset.act);
        });

        document.getElementById('pager').addEventListener('click', function (e) {
            const btn = e.target.closest('button[data-page]');
            if (!btn) return;
            const n = parseInt(btn.dataset.page, 10);
            if (n >= 1) {
                page = n;
                refresh();
            }
        });

        const readAll = document.getElementById('readAllBtn');
        if (readAll) {
            readAll.addEventListener('click', function () {
                bulk('read_all', 'Mark all read', 'success');
            });
        }
        const resolveAll = document.getElementById('resolveAllBtn');
        if (resolveAll) {
            resolveAll.addEventListener('click', function () {
                bulk('resolve_all', 'Resolve all', 'danger');
            });
        }
    });
})();
