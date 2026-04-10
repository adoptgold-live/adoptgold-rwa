<?php
declare(strict_types=1);

/**
 * POAdo RWA Cert Engine
 * ACE Claim Page
 *
 * File:
 * /rwa/cert/claims/ace.php
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

$csrfClaimAce = '';
try {
    if (function_exists('csrf_token')) {
        $csrfClaimAce = (string)csrf_token('rwa_claim_ace');
    }
} catch (Throwable $e) {
    $csrfClaimAce = '';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>ACE Claims · POAdo RWA</title>
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
    width:min(1100px,94vw);
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
input,select,button,textarea{
    border-radius:10px;
    border:1px solid var(--line);
    background:#111;
    color:var(--text);
    padding:10px 12px;
}
input,select,textarea{outline:none}
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
.badge.claimed{color:var(--green);border-color:#1f6d38}
.badge.claimable{color:var(--soft)}
.badge.partial{color:#ffd87a}
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
.weight{
    color:var(--blue);
    font-weight:700;
}
@media (max-width:900px){
    .grid{grid-template-columns:1fr 1fr}
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
            <div class="title">ACE Claims</div>
            <div class="sub">Wallet: <?=htmlspecialchars((string)$user['wallet'], ENT_QUOTES, 'UTF-8')?></div>
        </div>
        <div class="sub">User: <?=htmlspecialchars((string)($user['nickname'] ?? 'User'), ENT_QUOTES, 'UTF-8')?></div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="k">Weight Total</div>
            <div class="v" id="weightTotal">0.000000000</div>
        </div>
        <div class="card">
            <div class="k">Allocated Total</div>
            <div class="v" id="allocatedTotal">0.000000000</div>
        </div>
        <div class="card">
            <div class="k">Claimed Total</div>
            <div class="v" id="claimedTotal">0.000000000</div>
        </div>
        <div class="card">
            <div class="k">Claimable Total</div>
            <div class="v" id="claimableTotal">0.000000000</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Filter & Refresh</div>
        <div class="toolbar">
            <select id="statusFilter">
                <option value="">All Status</option>
                <option value="claimable">Claimable</option>
                <option value="partial">Partial</option>
                <option value="claimed">Claimed</option>
                <option value="void">Void</option>
            </select>
            <button id="reloadBtn">Reload</button>
        </div>
        <div class="note">
            This page reads from the canonical ACE claim ledger. ACE allocation is weighted by RK92-EMA sales and settlement records a treasury/admin payout transaction hash.
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">ACE Claim Rows</div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th style="width:40px"><input type="checkbox" id="selectAll"></th>
                    <th>Claim UID</th>
                    <th>Event UID</th>
                    <th>Weight</th>
                    <th>Allocated TON</th>
                    <th>Claimed TON</th>
                    <th>Remaining TON</th>
                    <th>Status</th>
                    <th>Snapshot Time</th>
                    <th>Claimed Tx</th>
                </tr>
                </thead>
                <tbody id="claimRows">
                <tr><td colspan="10">Loading...</td></tr>
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
        <div class="panel-title">ACE Claim Settlement</div>
        <div class="toolbar">
            <input type="text" id="claimedTxHash" placeholder="Treasury payout tx hash" style="min-width:320px">
            <button class="primary" id="claimSelectedBtn">Claim Selected</button>
        </div>
        <div class="note">
            This action records settlement in the ledger after the treasury/admin payout already exists.
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Console</div>
        <div class="console" id="console"></div>
    </div>

</div>

<script>
window.POADO_ACE_CLAIMS = {
    csrf: {
        claimAce: <?= json_encode($csrfClaimAce, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    },
    api: {
        summary: '/rwa/cert/api/my-ace-claims.php',
        claim: '/rwa/cert/api/claim-ace.php'
    }
};
</script>

<script>
(function () {
    'use strict';

    const CFG = window.POADO_ACE_CLAIMS || {};
    const API = CFG.api || {};
    const CSRF = CFG.csrf || {};

    const el = {
        weightTotal: document.getElementById('weightTotal'),
        allocatedTotal: document.getElementById('allocatedTotal'),
        claimedTotal: document.getElementById('claimedTotal'),
        claimableTotal: document.getElementById('claimableTotal'),
        statusFilter: document.getElementById('statusFilter'),
        reloadBtn: document.getElementById('reloadBtn'),
        claimRows: document.getElementById('claimRows'),
        prevPageBtn: document.getElementById('prevPageBtn'),
        nextPageBtn: document.getElementById('nextPageBtn'),
        pageInfo: document.getElementById('pageInfo'),
        claimedTxHash: document.getElementById('claimedTxHash'),
        claimSelectedBtn: document.getElementById('claimSelectedBtn'),
        selectAll: document.getElementById('selectAll'),
        console: document.getElementById('console')
    };

    const state = {
        page: 1,
        perPage: 20,
        totalPages: 1,
        items: []
    };

    function log(msg, level) {
        const row = document.createElement('div');
        row.className = 'logline';
        const now = new Date();
        row.textContent = `[${now.toLocaleTimeString()}] ${level ? '[' + level + '] ' : ''}${msg}`;
        el.console.prepend(row);
    }

    function fmt(v) {
        const n = Number(v || 0);
        return n.toFixed(9);
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

    function escapeHtml(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderRows(items) {
        if (!items || !items.length) {
            el.claimRows.innerHTML = '<tr><td colspan="10">No ACE claim rows found.</td></tr>';
            return;
        }

        el.claimRows.innerHTML = items.map(row => {
            const claimable = (row.status === 'claimable' || row.status === 'partial') && Number(row.remaining_ton || 0) > 0;
            return `
                <tr>
                    <td><input class="claim-check" type="checkbox" value="${escapeHtml(row.claim_uid)}" ${claimable ? '' : 'disabled'}></td>
                    <td>${escapeHtml(row.claim_uid || '')}</td>
                    <td>${escapeHtml(row.event_uid || '')}</td>
                    <td class="weight">${escapeHtml(fmt(row.weight_value))}</td>
                    <td>${escapeHtml(fmt(row.allocated_ton))}</td>
                    <td>${escapeHtml(fmt(row.claimed_ton))}</td>
                    <td>${escapeHtml(fmt(row.remaining_ton))}</td>
                    <td>${badge(row.status)}</td>
                    <td>${escapeHtml(row.snapshot_time || '')}</td>
                    <td>${escapeHtml(row.claimed_tx_hash || '')}</td>
                </tr>
            `;
        }).join('');
    }

    async function loadClaims() {
        const url = new URL(API.summary, window.location.origin);
        url.searchParams.set('page', String(state.page));
        url.searchParams.set('per_page', String(state.perPage));
        if (el.statusFilter.value) {
            url.searchParams.set('status', el.statusFilter.value);
        }

        log('Loading ACE claims...', 'INFO');

        const data = await getJson(url.toString(), { credentials: 'same-origin' });

        state.items = data.items || [];
        state.totalPages = Number((data.pagination || {}).total_pages || 1);

        el.weightTotal.textContent = fmt((data.summary || {}).weight_total);
        el.allocatedTotal.textContent = fmt((data.summary || {}).allocated_total_ton);
        el.claimedTotal.textContent = fmt((data.summary || {}).claimed_total_ton);
        el.claimableTotal.textContent = fmt((data.summary || {}).claimable_total_ton);
        el.pageInfo.textContent = `Page ${state.page} / ${state.totalPages}`;

        renderRows(state.items);

        el.prevPageBtn.disabled = state.page <= 1;
        el.nextPageBtn.disabled = state.page >= state.totalPages;
        el.selectAll.checked = false;

        log('ACE claims loaded.', 'OK');
    }

    async function claimSelected() {
        const claimUids = selectedClaimUids();
        const claimedTxHash = (el.claimedTxHash.value || '').trim();

        if (!claimUids.length) {
            log('No ACE claim rows selected.', 'WARN');
            return;
        }
        if (!claimedTxHash) {
            log('Treasury payout tx hash is required.', 'WARN');
            return;
        }

        log(`Submitting ${claimUids.length} ACE claim(s)...`, 'INFO');

        const fd = new FormData();
        claimUids.forEach(uid => fd.append('claim_uids[]', uid));
        fd.append('claimed_tx_hash', claimedTxHash);
        if (CSRF.claimAce) {
            fd.append('csrf_token', CSRF.claimAce);
        }

        const data = await getJson(API.claim, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        });

        log(`ACE claim settlement processed. Updated=${data.updated_count || 0}`, 'OK');
        await loadClaims();
    }

    function bind() {
        el.reloadBtn.addEventListener('click', function () {
            state.page = 1;
            loadClaims().catch(err => log(err.message, 'ERROR'));
        });

        el.statusFilter.addEventListener('change', function () {
            state.page = 1;
            loadClaims().catch(err => log(err.message, 'ERROR'));
        });

        el.prevPageBtn.addEventListener('click', function () {
            if (state.page > 1) {
                state.page--;
                loadClaims().catch(err => log(err.message, 'ERROR'));
            }
        });

        el.nextPageBtn.addEventListener('click', function () {
            if (state.page < state.totalPages) {
                state.page++;
                loadClaims().catch(err => log(err.message, 'ERROR'));
            }
        });

        el.claimSelectedBtn.addEventListener('click', function () {
            claimSelected().catch(err => log(err.message, 'ERROR'));
        });

        el.selectAll.addEventListener('change', function () {
            document.querySelectorAll('.claim-check:not(:disabled)').forEach(cb => {
                cb.checked = el.selectAll.checked;
            });
        });
    }

    bind();
    loadClaims().catch(err => log(err.message, 'ERROR'));
})();
</script>
</body>
</html>