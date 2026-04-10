<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * Admin Gold Packet Vault Ledger Viewer
 *
 * File:
 * /rwa/cert/admin/gold-packet.php
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

$csrfDistributeGoldPacket = '';
try {
    if (function_exists('csrf_token')) {
        $csrfDistributeGoldPacket = (string)csrf_token('rwa_distribute_gold_packet');
    }
} catch (Throwable $e) {
    $csrfDistributeGoldPacket = '';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Admin Gold Packet Vault · POAdo RWA</title>
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
    grid-template-columns:repeat(4,1fr);
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
.badge.vaulted{color:var(--orange)}
.badge.partial{color:#ffd87a}
.badge.distributed{color:var(--green);border-color:#1f6d38}
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
    .grid{grid-template-columns:repeat(2,1fr)}
}
@media (max-width:640px){
    .grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">

    <div class="topbar">
        <div>
            <div class="title">Admin Gold Packet Vault</div>
            <div class="sub">System-wide Gold Packet vault ledger viewer</div>
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
            <div class="k">Vaulted Total TON</div>
            <div class="v" id="vaultedTotal">0.000000000</div>
        </div>
        <div class="card">
            <div class="k">Distributed Total TON</div>
            <div class="v" id="distributedTotal">0.000000000</div>
        </div>
        <div class="card">
            <div class="k">Vault Balance TON</div>
            <div class="v" id="vaultBalance">0.000000000</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Filters</div>
        <div class="toolbar">
            <input type="text" id="claimUidFilter" placeholder="Claim UID">
            <input type="text" id="eventUidFilter" placeholder="Event UID">
            <input type="text" id="certUidFilter" placeholder="Cert UID">
            <select id="statusFilter">
                <option value="">All Status</option>
                <option value="vaulted">Vaulted</option>
                <option value="partial">Partial</option>
                <option value="distributed">Distributed</option>
                <option value="void">Void</option>
            </select>
            <button id="reloadBtn">Reload</button>
        </div>
        <div class="note">
            This page is the admin-wide viewer for the Gold Packet vault ledger. Rows are first vaulted, then distributed by admin settlement.
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Ledger Rows</div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th style="width:40px"><input type="checkbox" id="selectAll"></th>
                    <th>Claim UID</th>
                    <th>Event UID</th>
                    <th>Cert UID</th>
                    <th>Allocated TON</th>
                    <th>Distributed TON</th>
                    <th>Remaining TON</th>
                    <th>Status</th>
                    <th>Snapshot Time</th>
                    <th>Distributed Tx</th>
                    <th>Distributed At</th>
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
        <div class="panel-title">Admin Gold Packet Distribution</div>
        <div class="toolbar">
            <input type="text" id="distributedTxHash" placeholder="Treasury payout tx hash" style="min-width:320px">
            <button class="primary" id="distributeSelectedBtn">Distribute Selected Rows</button>
        </div>
        <div class="note">
            This uses the canonical Gold Packet distribution endpoint and records settlement to the vault ledger.
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Console</div>
        <div class="console" id="console"></div>
    </div>

</div>

<script>
window.POADO_ADMIN_GOLD_PACKET = {
    csrf: {
        distributeGoldPacket: <?= json_encode($csrfDistributeGoldPacket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    },
    api: {
        list: '/rwa/cert/api/admin-gold-packet.php',
        distribute: '/rwa/cert/api/distribute-gold-packet.php'
    }
};
</script>

<script>
(function () {
    'use strict';

    const CFG = window.POADO_ADMIN_GOLD_PACKET || {};
    const API = CFG.api || {};
    const CSRF = CFG.csrf || {};

    const el = {
        totalRows: document.getElementById('totalRows'),
        vaultedTotal: document.getElementById('vaultedTotal'),
        distributedTotal: document.getElementById('distributedTotal'),
        vaultBalance: document.getElementById('vaultBalance'),
        claimUidFilter: document.getElementById('claimUidFilter'),
        eventUidFilter: document.getElementById('eventUidFilter'),
        certUidFilter: document.getElementById('certUidFilter'),
        statusFilter: document.getElementById('statusFilter'),
        reloadBtn: document.getElementById('reloadBtn'),
        rows: document.getElementById('rows'),
        prevBtn: document.getElementById('prevBtn'),
        nextBtn: document.getElementById('nextBtn'),
        pageInfo: document.getElementById('pageInfo'),
        selectAll: document.getElementById('selectAll'),
        distributedTxHash: document.getElementById('distributedTxHash'),
        distributeSelectedBtn: document.getElementById('distributeSelectedBtn'),
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
        const n = Number(v || 0);
        return n.toFixed(9);
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
        if (!text || text.trim().startsWith('<')) {
            throw new Error('Invalid server response');
        }
        const data = JSON.parse(text);
        if (!res.ok || data.ok === false) {
            throw new Error(data.message || data.error || 'Request failed');
        }
        return data;
    }

    function badge(status) {
        const s = String(status || '');
        return `<span class="badge ${s}">${s || '-'}</span>`;
    }

    function selectedClaimUids() {
        return Array.from(document.querySelectorAll('.claim-check:checked'))
            .map(cb => cb.value)
            .filter(Boolean);
    }

    function renderRows(items) {
        if (!items || !items.length) {
            el.rows.innerHTML = '<tr><td colspan="11">No Gold Packet rows found.</td></tr>';
            return;
        }

        el.rows.innerHTML = items.map(row => {
            const distributable = (row.status === 'vaulted' || row.status === 'partial') && Number(row.remaining_ton || 0) > 0;
            return `
                <tr>
                    <td><input class="claim-check" type="checkbox" value="${escapeHtml(row.claim_uid)}" ${distributable ? '' : 'disabled'}></td>
                    <td>${escapeHtml(row.claim_uid || '')}</td>
                    <td>${escapeHtml(row.event_uid || '')}</td>
                    <td>${escapeHtml(row.cert_uid || '')}</td>
                    <td>${escapeHtml(fmt(row.allocated_ton))}</td>
                    <td>${escapeHtml(fmt(row.distributed_ton))}</td>
                    <td>${escapeHtml(fmt(row.remaining_ton))}</td>
                    <td>${badge(row.status)}</td>
                    <td>${escapeHtml(row.snapshot_time || '')}</td>
                    <td>${escapeHtml(row.distributed_tx_hash || '')}</td>
                    <td>${escapeHtml(row.distributed_at || '')}</td>
                </tr>
            `;
        }).join('');
    }

    async function loadRows() {
        const url = new URL(API.list, window.location.origin);
        url.searchParams.set('page', String(state.page));
        url.searchParams.set('per_page', String(state.perPage));

        if (el.claimUidFilter.value.trim()) url.searchParams.set('claim_uid', el.claimUidFilter.value.trim());
        if (el.eventUidFilter.value.trim()) url.searchParams.set('event_uid', el.eventUidFilter.value.trim());
        if (el.certUidFilter.value.trim()) url.searchParams.set('cert_uid', el.certUidFilter.value.trim());
        if (el.statusFilter.value) url.searchParams.set('status', el.statusFilter.value);

        log('Loading admin Gold Packet rows...', 'INFO');

        const data = await getJson(url.toString(), { credentials: 'same-origin' });

        el.totalRows.textContent = String((data.summary || {}).total_rows || 0);
        el.vaultedTotal.textContent = fmt((data.summary || {}).vaulted_total_ton);
        el.distributedTotal.textContent = fmt((data.summary || {}).distributed_total_ton);
        el.vaultBalance.textContent = fmt((data.summary || {}).vault_balance_ton);

        state.totalPages = Number((data.pagination || {}).total_pages || 1);
        el.pageInfo.textContent = `Page ${state.page} / ${state.totalPages}`;

        renderRows(data.items || []);

        el.prevBtn.disabled = state.page <= 1;
        el.nextBtn.disabled = state.page >= state.totalPages;
        el.selectAll.checked = false;

        log('Admin Gold Packet rows loaded.', 'OK');
    }

    async function distributeSelected() {
        const claimUids = selectedClaimUids();
        const distributedTxHash = (el.distributedTxHash.value || '').trim();

        if (!claimUids.length) {
            log('No Gold Packet rows selected.', 'WARN');
            return;
        }
        if (!distributedTxHash) {
            log('Treasury payout tx hash is required.', 'WARN');
            return;
        }

        log(`Submitting distribution for ${claimUids.length} Gold Packet row(s)...`, 'INFO');

        const fd = new FormData();
        claimUids.forEach(uid => fd.append('claim_uids[]', uid));
        fd.append('distributed_tx_hash', distributedTxHash);
        if (CSRF.distributeGoldPacket) fd.append('csrf_token', CSRF.distributeGoldPacket);

        const data = await getJson(API.distribute, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        });

        log(`Gold Packet distribution processed. Updated=${data.updated_count || 0}`, 'OK');
        await loadRows();
    }

    function bind() {
        el.reloadBtn.addEventListener('click', function () {
            state.page = 1;
            loadRows().catch(err => log(err.message, 'ERROR'));
        });

        [el.claimUidFilter, el.eventUidFilter, el.certUidFilter].forEach(inp => {
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
            document.querySelectorAll('.claim-check:not(:disabled)').forEach(cb => {
                cb.checked = el.selectAll.checked;
            });
        });

        el.distributeSelectedBtn.addEventListener('click', function () {
            distributeSelected().catch(err => log(err.message, 'ERROR'));
        });
    }

    bind();
    loadRows().catch(err => log(err.message, 'ERROR'));
})();
</script>
</body>
</html>