<?php
declare(strict_types=1);

/**
 * POAdo RWA Gold Packet Vault
 *
 * File:
 * /rwa/vault/index.php
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
    SELECT id, wallet, nickname, role, is_active
    FROM users
    WHERE wallet = :wallet
    LIMIT 1
");
$stmt->execute([':wallet' => $wallet]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
    die('User inactive or not found');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Gold Packet Vault · POAdo RWA</title>
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
    --blue:#79b8ff;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
}
.wrap{
    width:min(1180px,94vw);
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
    font-size:24px;
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
    font-size:24px;
    font-weight:700;
    color:var(--soft);
}
.toolbar{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:16px;
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
button:disabled{
    opacity:.55;
    cursor:not-allowed;
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
.table-wrap{
    overflow:auto;
    border:1px solid #2a2413;
    border-radius:12px;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:980px;
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
.badge.vaulted{color:var(--soft)}
.badge.partial{color:#ffd87a}
.badge.distributed{color:var(--green);border-color:#1f6d38}
.badge.void{color:var(--red);border-color:#6d1f1f}
.console{
    background:#050505;
    border:1px solid #221d10;
    border-radius:12px;
    padding:12px;
    min-height:120px;
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
@media (max-width:900px){
    .grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">

    <div class="topbar">
        <div>
            <div class="title">Gold Packet Vault</div>
            <div class="sub">Wallet: <?=htmlspecialchars((string)$user['wallet'], ENT_QUOTES, 'UTF-8')?></div>
        </div>
        <div class="sub">User: <?=htmlspecialchars((string)($user['nickname'] ?? 'User'), ENT_QUOTES, 'UTF-8')?></div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="k">Vaulted Total</div>
            <div class="v" id="vaultedTotal">0.000000000</div>
        </div>
        <div class="card">
            <div class="k">Distributed Total</div>
            <div class="v" id="distributedTotal">0.000000000</div>
        </div>
        <div class="card">
            <div class="k">Vault Balance</div>
            <div class="v" id="vaultBalance">0.000000000</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Filter & Refresh</div>
        <div class="toolbar">
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
            Gold Packet allocations are first recorded into the vault ledger, then later distributed by the Gold Packet engine. This page is the canonical read-side vault monitor.
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Vault Events</div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Claim UID</th>
                    <th>Event UID</th>
                    <th>Cert UID</th>
                    <th>Allocated TON</th>
                    <th>Distributed TON</th>
                    <th>Remaining TON</th>
                    <th>Status</th>
                    <th>Snapshot Time</th>
                    <th>Distributed Tx</th>
                </tr>
                </thead>
                <tbody id="vaultRows">
                <tr><td colspan="9">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pager">
            <button id="prevPageBtn">Prev</button>
            <span class="sub" id="pageInfo">Page 1</span>
            <button id="nextPageBtn">Next</button>
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Console</div>
        <div class="console" id="console"></div>
    </div>

</div>

<script>
window.POADO_GOLD_PACKET_VAULT = {
    api: {
        balance: '/rwa/cert/api/my-gold-packet-balance.php'
    }
};
</script>

<script>
(function () {
    'use strict';

    const CFG = window.POADO_GOLD_PACKET_VAULT || {};
    const API = CFG.api || {};

    const el = {
        vaultedTotal: document.getElementById('vaultedTotal'),
        distributedTotal: document.getElementById('distributedTotal'),
        vaultBalance: document.getElementById('vaultBalance'),
        statusFilter: document.getElementById('statusFilter'),
        reloadBtn: document.getElementById('reloadBtn'),
        vaultRows: document.getElementById('vaultRows'),
        prevPageBtn: document.getElementById('prevPageBtn'),
        nextPageBtn: document.getElementById('nextPageBtn'),
        pageInfo: document.getElementById('pageInfo'),
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

    function renderRows(items) {
        if (!items || !items.length) {
            el.vaultRows.innerHTML = '<tr><td colspan="9">No Gold Packet vault rows found.</td></tr>';
            return;
        }

        el.vaultRows.innerHTML = items.map(row => `
            <tr>
                <td>${escapeHtml(row.claim_uid || '')}</td>
                <td>${escapeHtml(row.event_uid || '')}</td>
                <td>${escapeHtml(row.cert_uid || '')}</td>
                <td>${escapeHtml(fmt(row.allocated_ton))}</td>
                <td>${escapeHtml(fmt(row.distributed_ton))}</td>
                <td>${escapeHtml(fmt(row.remaining_ton))}</td>
                <td>${badge(row.status)}</td>
                <td>${escapeHtml(row.snapshot_time || '')}</td>
                <td>${escapeHtml(row.distributed_tx_hash || '')}</td>
            </tr>
        `).join('');
    }

    async function loadVault() {
        const url = new URL(API.balance, window.location.origin);
        url.searchParams.set('page', String(state.page));
        url.searchParams.set('per_page', String(state.perPage));
        if (el.statusFilter.value) {
            url.searchParams.set('status', el.statusFilter.value);
        }

        log('Loading Gold Packet vault...', 'INFO');

        const data = await getJson(url.toString(), { credentials: 'same-origin' });

        el.vaultedTotal.textContent = fmt((data.summary || {}).vaulted_total_ton);
        el.distributedTotal.textContent = fmt((data.summary || {}).distributed_total_ton);
        el.vaultBalance.textContent = fmt((data.summary || {}).vault_balance_ton);

        state.totalPages = Number((data.pagination || {}).total_pages || 1);
        el.pageInfo.textContent = `Page ${state.page} / ${state.totalPages}`;

        renderRows(data.items || []);

        el.prevPageBtn.disabled = state.page <= 1;
        el.nextPageBtn.disabled = state.page >= state.totalPages;

        log('Gold Packet vault loaded.', 'OK');
    }

    function bind() {
        el.reloadBtn.addEventListener('click', function () {
            state.page = 1;
            loadVault().catch(err => log(err.message, 'ERROR'));
        });

        el.statusFilter.addEventListener('change', function () {
            state.page = 1;
            loadVault().catch(err => log(err.message, 'ERROR'));
        });

        el.prevPageBtn.addEventListener('click', function () {
            if (state.page > 1) {
                state.page--;
                loadVault().catch(err => log(err.message, 'ERROR'));
            }
        });

        el.nextPageBtn.addEventListener('click', function () {
            if (state.page < state.totalPages) {
                state.page++;
                loadVault().catch(err => log(err.message, 'ERROR'));
            }
        });
    }

    bind();
    loadVault().catch(err => log(err.message, 'ERROR'));
})();
</script>
</body>
</html>