<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * Admin Treasury Retained Ledger Viewer
 *
 * File:
 * /rwa/cert/admin/treasury-retained.php
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/rwa-session.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/dashboard/inc/session-user.php';

$wallet = get_wallet_session();
if (!$wallet) {
    header('Location: /rwa/index.php');
    exit;
}

db_connect();
$pdo = $GLOBALS['pdo'];

$stmt = $pdo->prepare("
    SELECT id, wallet, nickname, role, is_active, is_admin, is_senior
    FROM users
    WHERE wallet = :wallet
    LIMIT 1
");
$stmt->execute([':wallet' => $wallet]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$user ||
    (int)($user['is_active'] ?? 0) !== 1 ||
    (empty($user['is_admin']) && empty($user['is_senior']))
) {
    die('Admin access only');
}

$csrfReconcileTreasury = '';
try {
    if (function_exists('csrf_token')) {
        $csrfReconcileTreasury = (string)csrf_token('rwa_reconcile_treasury_retained');
    }
} catch (Throwable $e) {
    $csrfReconcileTreasury = '';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Admin Treasury Retained · POAdo RWA</title>
<style>
:root{
    --bg:#050505;
    --panel:#0f0f0f;
    --line:#6f5b1d;
    --gold:#d4af37;
    --soft:#f3d77a;
    --text:#f5e7b8;
    --muted:#aa9a68;
    --green:#69ff8e;
    --red:#ff7b7b;
    --orange:#ffbf69;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
}
.wrap{
    width:min(1280px,95vw);
    margin:20px auto 40px;
}
.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:18px;
}
.title{
    font-size:26px;
    font-weight:700;
    color:var(--gold);
}
.sub{
    font-size:13px;
    color:var(--muted);
}
.grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
    margin-bottom:18px;
}
.card{
    background:linear-gradient(180deg,#121212,#0b0b0b);
    border:1px solid var(--line);
    border-radius:14px;
    padding:16px;
}
.k{
    font-size:12px;
    color:var(--muted);
    margin-bottom:8px;
    text-transform:uppercase;
    letter-spacing:.08em;
}
.v{
    font-size:22px;
    font-weight:700;
    color:var(--soft);
}
.panel{
    background:linear-gradient(180deg,#111,#090909);
    border:1px solid var(--line);
    border-radius:14px;
    padding:16px;
    margin-bottom:16px;
}
.panel-title{
    font-size:16px;
    font-weight:700;
    color:var(--gold);
    margin-bottom:12px;
}
.toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:12px;
}
input,select,button{
    border-radius:10px;
    border:1px solid var(--line);
    background:#111;
    color:var(--text);
    padding:10px 12px;
}
button{
    cursor:pointer;
    background:linear-gradient(180deg,#1a1a1a,#111);
}
button.primary{
    border-color:#b89018;
    color:#111;
    background:linear-gradient(180deg,#f0cf61,#cda52d);
    font-weight:700;
}
button:disabled{
    opacity:.55;
    cursor:not-allowed;
}
.table-wrap{
    overflow:auto;
    border:1px solid #2a2413;
    border-radius:12px;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:1280px;
}
th,td{
    padding:10px 12px;
    border-bottom:1px solid #221d10;
    text-align:left;
    font-size:13px;
}
th{
    color:var(--gold);
    background:#0d0d0d;
}
.badge{
    display:inline-block;
    padding:4px 8px;
    border-radius:999px;
    border:1px solid #4b3f19;
    color:var(--soft);
    font-size:12px;
}
.badge.retained{color:var(--orange)}
.badge.reconciled{color:var(--green);border-color:#1f6d38}
.badge.void{color:var(--red);border-color:#6d1f1f}
.console{
    background:#050505;
    border:1px solid #221d10;
    border-radius:12px;
    padding:12px;
    min-height:140px;
    font-family:monospace;
    font-size:12px;
    overflow:auto;
}
.logline{padding:2px 0;color:#d7cda5}
.pager{
    display:flex;
    gap:10px;
    justify-content:flex-end;
    margin-top:12px;
    flex-wrap:wrap;
}
.note{
    color:var(--muted);
    font-size:12px;
    line-height:1.5;
}
@media (max-width:1100px){
    .grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">

    <div class="topbar">
        <div>
            <div class="title">Admin Treasury Retained</div>
            <div class="sub">System-wide Treasury retained ledger viewer</div>
        </div>
        <div class="sub">
            Admin: <?=htmlspecialchars((string)($user['nickname'] ?? 'Admin'), ENT_QUOTES, 'UTF-8')?> ·
            <?=htmlspecialchars((string)$user['wallet'], ENT_QUOTES, 'UTF-8')?>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="k">Total Rows</div>
            <div class="v" id="totalRows">0</div>
        </div>
        <div class="card">
            <div class="k">Retained Total TON</div>
            <div class="v" id="retainedTotal">0.000000000</div>
        </div>
        <div class="card">
            <div class="k">Treasury Wallet</div>
            <div class="v" id="treasuryWallet" style="font-size:14px;word-break:break-all;">
                UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Filters</div>
        <div class="toolbar">
            <input type="text" id="retainUidFilter" placeholder="Retain UID">
            <input type="text" id="eventUidFilter" placeholder="Event UID">
            <input type="text" id="certUidFilter" placeholder="Cert UID">
            <select id="statusFilter">
                <option value="">All Status</option>
                <option value="retained">Retained</option>
                <option value="reconciled">Reconciled</option>
                <option value="void">Void</option>
            </select>
            <button id="reloadBtn">Reload</button>
        </div>
        <div class="note">
            This page is the admin-wide viewer for the Treasury retained ledger. Reconciliation marks retained rows as accounted for after treasury review.
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Ledger Rows</div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th style="width:40px"><input type="checkbox" id="selectAll"></th>
                    <th>Retain UID</th>
                    <th>Event UID</th>
                    <th>Cert UID</th>
                    <th>Marketplace</th>
                    <th>Treasury Wallet</th>
                    <th>Retained TON</th>
                    <th>Status</th>
                    <th>Snapshot Time</th>
                    <th>Note</th>
                    <th>Created At</th>
                </tr>
                </thead>
                <tbody id="rows">
                <tr><td colspan="11">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pager">
            <button id="prevBtn">Prev</button>
            <span class="sub" id="pageInfo">Page 1</span>
            <button id="nextBtn">Next</button>
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Treasury Reconciliation</div>
        <div class="toolbar">
            <input type="text" id="reconciledRef" placeholder="Reconciliation reference" style="min-width:260px">
            <input type="text" id="noteAppend" placeholder="Optional note" style="min-width:260px">
            <button class="primary" id="reconcileSelectedBtn">Reconcile Selected Rows</button>
        </div>
        <div class="note">
            This uses the canonical Treasury reconciliation endpoint and appends reconciliation trace into the retained ledger note field.
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Console</div>
        <div class="console" id="console"></div>
    </div>

</div>

<script>
window.POADO_ADMIN_TREASURY_RETAINED = {
    csrf: {
        reconcileTreasury: <?= json_encode($csrfReconcileTreasury, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    },
    api: {
        list: '/rwa/cert/api/admin-treasury-retained.php',
        reconcile: '/rwa/cert/api/reconcile-treasury-retained.php'
    }
};
</script>

<script>
(function () {
    'use strict';

    const CFG = window.POADO_ADMIN_TREASURY_RETAINED || {};
    const API = CFG.api || {};
    const CSRF = CFG.csrf || {};

    const el = {
        totalRows: document.getElementById('totalRows'),
        retainedTotal: document.getElementById('retainedTotal'),
        retainUidFilter: document.getElementById('retainUidFilter'),
        eventUidFilter: document.getElementById('eventUidFilter'),
        certUidFilter: document.getElementById('certUidFilter'),
        statusFilter: document.getElementById('statusFilter'),
        reloadBtn: document.getElementById('reloadBtn'),
        rows: document.getElementById('rows'),
        prevBtn: document.getElementById('prevBtn'),
        nextBtn: document.getElementById('nextBtn'),
        pageInfo: document.getElementById('pageInfo'),
        selectAll: document.getElementById('selectAll'),
        reconciledRef: document.getElementById('reconciledRef'),
        noteAppend: document.getElementById('noteAppend'),
        reconcileSelectedBtn: document.getElementById('reconcileSelectedBtn'),
        console: document.getElementById('console')
    };

    const state = {
        page: 1,
        perPage: 20,
        totalPages: 1
    };

    function log(msg, level) {
        const row = document.createElement('div');
        row.className = 'logline';
        row.textContent = `[${new Date().toLocaleTimeString()}] ${level ? '[' + level + '] ' : ''}${msg}`;
        el.console.prepend(row);
    }

    function fmt(v) {
        return Number(v || 0).toFixed(9);
    }

    function escapeHtml(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    async function getJson(url, options) {
        const res = await fetch(url, options || {});
        const text = await res.text();
        if (!text || text.trim().startsWith('<')) throw new Error('Invalid server response');
        const data = JSON.parse(text);
        if (!res.ok || data.ok === false) throw new Error(data.message || data.error || 'Request failed');
        return data;
    }

    function badge(status) {
        const s = String(status || '');
        return `<span class="badge ${s}">${s || '-'}</span>`;
    }

    function selectedRetainUids() {
        return Array.from(document.querySelectorAll('.retain-check:checked'))
            .map(cb => cb.value)
            .filter(Boolean);
    }

    function renderRows(items) {
        if (!items || !items.length) {
            el.rows.innerHTML = '<tr><td colspan="11">No Treasury retained rows found.</td></tr>';
            return;
        }

        el.rows.innerHTML = items.map(row => {
            const reconcilable = row.status === 'retained';
            return `
                <tr>
                    <td><input class="retain-check" type="checkbox" value="${escapeHtml(row.retain_uid)}" ${reconcilable ? '' : 'disabled'}></td>
                    <td>${escapeHtml(row.retain_uid || '')}</td>
                    <td>${escapeHtml(row.event_uid || '')}</td>
                    <td>${escapeHtml(row.cert_uid || '')}</td>
                    <td>${escapeHtml(row.marketplace || '')}</td>
                    <td>${escapeHtml(row.treasury_wallet || '')}</td>
                    <td>${escapeHtml(fmt(row.retained_ton))}</td>
                    <td>${badge(row.status)}</td>
                    <td>${escapeHtml(row.snapshot_time || '')}</td>
                    <td>${escapeHtml(row.note || '')}</td>
                    <td>${escapeHtml(row.created_at || '')}</td>
                </tr>
            `;
        }).join('');
    }

    async function loadRows() {
        const url = new URL(API.list, window.location.origin);
        url.searchParams.set('page', String(state.page));
        url.searchParams.set('per_page', String(state.perPage));

        if (el.retainUidFilter.value.trim()) url.searchParams.set('retain_uid', el.retainUidFilter.value.trim());
        if (el.eventUidFilter.value.trim()) url.searchParams.set('event_uid', el.eventUidFilter.value.trim());
        if (el.certUidFilter.value.trim()) url.searchParams.set('cert_uid', el.certUidFilter.value.trim());
        if (el.statusFilter.value) url.searchParams.set('status', el.statusFilter.value);

        log('Loading admin Treasury retained rows...', 'INFO');

        const data = await getJson(url.toString(), { credentials: 'same-origin' });

        el.totalRows.textContent = String((data.summary || {}).total_rows || 0);
        el.retainedTotal.textContent = fmt((data.summary || {}).retained_total_ton);

        state.totalPages = Number((data.pagination || {}).total_pages || 1);
        el.pageInfo.textContent = `Page ${state.page} / ${state.totalPages}`;

        renderRows(data.items || []);

        el.prevBtn.disabled = state.page <= 1;
        el.nextBtn.disabled = state.page >= state.totalPages;
        el.selectAll.checked = false;

        log('Admin Treasury retained rows loaded.', 'OK');
    }

    async function reconcileSelected() {
        const retainUids = selectedRetainUids();
        const reconciledRef = (el.reconciledRef.value || '').trim();
        const note = (el.noteAppend.value || '').trim();

        if (!retainUids.length) {
            log('No Treasury retained rows selected.', 'WARN');
            return;
        }
        if (!reconciledRef) {
            log('Reconciliation reference is required.', 'WARN');
            return;
        }

        log(`Reconciling ${retainUids.length} Treasury row(s)...`, 'INFO');

        const fd = new FormData();
        retainUids.forEach(uid => fd.append('retain_uids[]', uid));
        fd.append('reconciled_ref', reconciledRef);
        if (note) fd.append('note', note);
        if (CSRF.reconcileTreasury) fd.append('csrf_token', CSRF.reconcileTreasury);

        const data = await getJson(API.reconcile, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        });

        log(`Treasury reconciliation processed. Updated=${data.updated_count || 0}`, 'OK');
        await loadRows();
    }

    function bind() {
        el.reloadBtn.addEventListener('click', function () {
            state.page = 1;
            loadRows().catch(err => log(err.message, 'ERROR'));
        });

        [el.retainUidFilter, el.eventUidFilter, el.certUidFilter].forEach(inp => {
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    state.page = 1;
                    loadRows().catch(err => log(err.message, 'ERROR'));
                }
            });
        });

        el.statusFilter.addEventListener('change', function () {
            state.page = 1;
            loadRows().catch(err => log(err.message, 'ERROR'));
        });

        el.prevBtn.addEventListener('click', function () {
            if (state.page > 1) {
                state.page--;
                loadRows().catch(err => log(err.message, 'ERROR'));
            }
        });

        el.nextBtn.addEventListener('click', function () {
            if (state.page < state.totalPages) {
                state.page++;
                loadRows().catch(err => log(err.message, 'ERROR'));
            }
        });

        el.selectAll.addEventListener('change', function () {
            document.querySelectorAll('.retain-check:not(:disabled)').forEach(cb => {
                cb.checked = el.selectAll.checked;
            });
        });

        el.reconcileSelectedBtn.addEventListener('click', function () {
            reconcileSelected().catch(err => log(err.message, 'ERROR'));
        });
    }

    bind();
    loadRows().catch(err => log(err.message, 'ERROR'));
})();
</script>
</body>
</html>