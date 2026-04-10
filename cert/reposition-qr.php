<?php
declare(strict_types=1);

/**
 * /var/www/html/public/rwa/cert/reposition-qr.php
 * Version: v2.0.0-20260329-png-overlay-reposition
 *
 * Purpose:
 * - interactive QR reposition tool for all locked NFT templates
 * - uses a REAL PNG overlay generated locally in browser
 * - drag the PNG
 * - resize the PNG
 * - live preview x / y / size
 * - generate updated PHP map block for copy/paste
 *
 * No server QR preview endpoint.
 * No /dashboard/inc/qr.php.
 * No standalone QR dependency.
 */

require_once '/var/www/html/public/rwa/inc/core/bootstrap.php';
require_once '/var/www/html/public/rwa/cert/api/_meta-image-map.php';

function h($v): string
{
    if ($v === null) return '';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_scalar($v)) return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    return htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES, 'UTF-8');
}

$map = poado_cert_meta_image_map();
$templates = [];

foreach ($map as $key => $cfg) {
    $templates[$key] = [
        'cert_type' => (string)$cfg['cert_type'],
        'label'     => (string)$cfg['label'],
        'rwa_key'   => (string)$cfg['rwa_key'],
        'rwa_code'  => (string)$cfg['rwa_code'],
        'family'    => (string)$cfg['family'],
        'file'      => (string)$cfg['file'],
        'image_url' => '/rwa/metadata/nft/' . (string)$cfg['file'],
        'qr'        => [
            'x'    => (int)$cfg['qr']['x'],
            'y'    => (int)$cfg['qr']['y'],
            'size' => (int)$cfg['qr']['size'],
        ],
    ];
}

$footerText = '© 2026 Blockchain Group RWA FZCO (DMCC, Dubai, UAE) · RWA Standard Organisation (RSO). All rights reserved.';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>RWA QR Reposition Tool</title>
<style>
:root{
    --bg:#07090f;
    --bg2:#101827;
    --panel:#131b29;
    --line:rgba(214,177,90,.24);
    --line2:rgba(102,199,255,.18);
    --text:#f3efe4;
    --muted:#97a3b2;
    --gold:#d6b15a;
    --blue:#66c7ff;
    --green:#71d68f;
    --red:#ff6b6b;
    --shadow:0 10px 30px rgba(0,0,0,.35);
    --radius:18px;
}
*{box-sizing:border-box}
html,body{
    margin:0;
    background:
        radial-gradient(circle at top right, rgba(214,177,90,.08), transparent 30%),
        radial-gradient(circle at top left, rgba(102,199,255,.08), transparent 28%),
        linear-gradient(180deg,var(--bg),var(--bg2));
    color:var(--text);
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
a{color:var(--blue);text-decoration:none}
a:hover{text-decoration:underline}
button,input,select,textarea{font:inherit}
.page{max-width:1500px;margin:0 auto;padding:18px 12px 42px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
.brand{color:var(--gold);font-size:14px;letter-spacing:.08em}
.nav-link{
    display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:10px 14px;
    border:1px solid var(--line);border-radius:999px;color:var(--text);background:rgba(255,255,255,.03);
}
.hero{
    border:1px solid var(--line);border-radius:24px;
    background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.015));
    box-shadow:var(--shadow);padding:18px;margin-bottom:16px;
}
.hero h1{margin:0 0 8px;font-size:22px;line-height:1.25}
.hero p{margin:0;color:var(--muted);font-size:13px;line-height:1.7}
.layout{display:grid;grid-template-columns:380px 1fr;gap:16px}
.panel{
    border:1px solid var(--line);border-radius:var(--radius);
    background:rgba(10,16,28,.84);box-shadow:var(--shadow);overflow:hidden;
}
.panel-head{padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.06);color:var(--gold);font-size:13px;letter-spacing:.06em}
.panel-body{padding:16px}
.stack{display:flex;flex-direction:column;gap:14px}
.field{display:flex;flex-direction:column;gap:8px}
.label{font-size:12px;color:var(--muted);letter-spacing:.05em;text-transform:uppercase}
.select,.input,.textarea{
    width:100%;min-height:44px;border-radius:12px;border:1px solid var(--line);
    background:#000;color:#fff;padding:10px 12px;
}
.textarea{min-height:280px;resize:vertical;line-height:1.55}
.row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.btns{display:flex;flex-wrap:wrap;gap:10px}
.btn{
    display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 14px;
    border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.03);color:var(--text);cursor:pointer;
}
.btn.primary{border-color:rgba(113,214,143,.35);box-shadow:0 0 0 1px rgba(113,214,143,.08) inset}
.kv{display:grid;grid-template-columns:100px 1fr;gap:8px 12px}
.kv .k{font-size:12px;color:var(--muted);text-transform:uppercase}
.kv .v{font-size:13px;color:var(--text);word-break:break-word}
.stage-wrap{position:relative}
.stage-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:12px}
.stage-info{color:var(--muted);font-size:12px}
.stage-shell{
    border:1px solid var(--line2);border-radius:16px;background:rgba(255,255,255,.02);
    padding:12px;overflow:auto;
}
.stage{position:relative;display:inline-block;line-height:0;user-select:none}
.stage img.template{display:block;max-width:100%;height:auto}
.qr-box{
    position:absolute;
    border:2px solid #ffeb3b;
    box-shadow:0 0 0 1px rgba(255,235,59,.25),0 0 20px rgba(255,235,59,.18);
    background:rgba(255,255,255,.04);
    cursor:move;
    overflow:visible;
}
.qr-image{
    display:block;
    width:100%;
    height:100%;
    object-fit:contain;
    pointer-events:none;
    background:#fff;
}
.qr-cross-x,.qr-cross-y{
    position:absolute;background:rgba(102,199,255,.9);pointer-events:none
}
.qr-cross-x{left:0;right:0;top:50%;height:1px;transform:translateY(-.5px)}
.qr-cross-y{top:0;bottom:0;left:50%;width:1px;transform:translateX(-.5px)}
.resize-handle{
    position:absolute;right:-8px;bottom:-8px;width:18px;height:18px;border-radius:50%;
    background:var(--red);border:2px solid #fff;cursor:nwse-resize;box-shadow:0 0 0 2px rgba(255,107,107,.15);
}
.notice{
    font-size:12px;color:#d6ecff;line-height:1.6;border:1px solid rgba(102,199,255,.22);
    background:rgba(102,199,255,.06);border-radius:14px;padding:12px 14px;
}
.footer{margin-top:24px;text-align:center;color:#8d98a6;font-size:12px;line-height:1.6}
.small{font-size:12px;color:var(--muted)}
@media (max-width:1100px){.layout{grid-template-columns:1fr}}
@media (max-width:640px){
    .page{padding:14px 10px 28px}
    .hero{padding:16px}
    .hero h1{font-size:18px}
    .row{grid-template-columns:1fr}
    .kv{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div class="brand">ADOPT.GOLD · QR REPOSITION TOOL</div>
        <a class="nav-link" href="/rwa/cert/index.php">← Back to Cert Dashboard</a>
    </div>

    <section class="hero">
        <h1>PNG QR reposition tool</h1>
        <p>
            Drag the QR PNG, resize it, and copy the new <span class="small">x / y / size</span> values.
            No QR preview endpoint. No dashboard QR helper. This is manual positioning only.
        </p>
    </section>

    <div class="layout">
        <section class="panel">
            <div class="panel-head">POSITION CONTROL</div>
            <div class="panel-body">
                <div class="stack">
                    <div class="field">
                        <label class="label" for="templateSelect">Template</label>
                        <select id="templateSelect" class="select"></select>
                    </div>

                    <div class="kv">
                        <div class="k">Label</div><div class="v" id="infoLabel">-</div>
                        <div class="k">RWA Key</div><div class="v" id="infoRwaKey">-</div>
                        <div class="k">Family</div><div class="v" id="infoFamily">-</div>
                        <div class="k">File</div><div class="v" id="infoFile">-</div>
                    </div>

                    <div class="row">
                        <div class="field">
                            <label class="label" for="inputX">X</label>
                            <input id="inputX" class="input" type="number" step="1">
                        </div>
                        <div class="field">
                            <label class="label" for="inputY">Y</label>
                            <input id="inputY" class="input" type="number" step="1">
                        </div>
                        <div class="field">
                            <label class="label" for="inputSize">Size</label>
                            <input id="inputSize" class="input" type="number" step="1" min="20">
                        </div>
                    </div>

                    <div class="btns">
                        <button id="applyBtn" class="btn primary" type="button">Apply Values</button>
                        <button id="resetBtn" class="btn" type="button">Reset Current</button>
                        <button id="resetAllBtn" class="btn" type="button">Reset All</button>
                        <button id="copyMapBtn" class="btn" type="button">Copy PHP Map</button>
                    </div>

                    <div class="notice">
                        This overlay is a real PNG generated locally in browser canvas. Drag to move. Drag the red circle to resize.
                    </div>

                    <div class="field">
                        <label class="label" for="mapOutput">Updated PHP map output</label>
                        <textarea id="mapOutput" class="textarea" spellcheck="false"></textarea>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">LIVE TEMPLATE PREVIEW</div>
            <div class="panel-body">
                <div class="stage-wrap">
                    <div class="stage-toolbar">
                        <div class="stage-info">
                            Natural image size:
                            <span id="naturalSizeText">-</span>
                        </div>
                        <div class="stage-info">
                            Current:
                            x=<span id="liveX">0</span>,
                            y=<span id="liveY">0</span>,
                            size=<span id="liveSize">0</span>
                        </div>
                    </div>

                    <div class="stage-shell">
                        <div id="stage" class="stage">
                            <img id="templateImage" class="template" src="" alt="Template preview">
                            <div id="qrBox" class="qr-box">
                                <img id="qrImage" class="qr-image" src="" alt="QR PNG overlay">
                                <div class="qr-cross-x"></div>
                                <div class="qr-cross-y"></div>
                                <div id="resizeHandle" class="resize-handle"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div class="footer"><?= h($footerText) ?></div>
</div>

<script>
const TEMPLATE_DATA = <?= json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>;
const STORAGE_KEY = 'poado_reposition_qr_map_v2_png';

const templateSelect = document.getElementById('templateSelect');
const infoLabel = document.getElementById('infoLabel');
const infoRwaKey = document.getElementById('infoRwaKey');
const infoFamily = document.getElementById('infoFamily');
const infoFile = document.getElementById('infoFile');

const inputX = document.getElementById('inputX');
const inputY = document.getElementById('inputY');
const inputSize = document.getElementById('inputSize');

const applyBtn = document.getElementById('applyBtn');
const resetBtn = document.getElementById('resetBtn');
const resetAllBtn = document.getElementById('resetAllBtn');
const copyMapBtn = document.getElementById('copyMapBtn');

const mapOutput = document.getElementById('mapOutput');

const stage = document.getElementById('stage');
const templateImage = document.getElementById('templateImage');
const qrBox = document.getElementById('qrBox');
const qrImage = document.getElementById('qrImage');
const resizeHandle = document.getElementById('resizeHandle');

const naturalSizeText = document.getElementById('naturalSizeText');
const liveX = document.getElementById('liveX');
const liveY = document.getElementById('liveY');
const liveSize = document.getElementById('liveSize');

let workingMap = deepClone(TEMPLATE_DATA);
let currentKey = Object.keys(workingMap)[0] || null;
let dragState = null;
let resizeState = null;
let qrPngDataUri = '';

function deepClone(obj) {
    return JSON.parse(JSON.stringify(obj));
}

function toInt(value, fallback = 0) {
    const n = parseInt(String(value), 10);
    return Number.isFinite(n) ? n : fallback;
}

function loadSavedMap() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return;

        Object.keys(workingMap).forEach((key) => {
            if (parsed[key] && parsed[key].qr) {
                workingMap[key].qr.x = toInt(parsed[key].qr.x, workingMap[key].qr.x);
                workingMap[key].qr.y = toInt(parsed[key].qr.y, workingMap[key].qr.y);
                workingMap[key].qr.size = toInt(parsed[key].qr.size, workingMap[key].qr.size);
            }
        });
    } catch (err) {
        console.warn('loadSavedMap failed', err);
    }
}

function saveMap() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(workingMap));
    } catch (err) {
        console.warn('saveMap failed', err);
    }
}

function makeQrPngDataUri() {
    const size = 512;
    const modules = 29;
    const quiet = 2;
    const total = modules + quiet * 2;
    const scale = Math.floor(size / total);
    const real = total * scale;

    const canvas = document.createElement('canvas');
    canvas.width = real;
    canvas.height = real;

    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, real, real);

    function cell(x, y, black = true) {
        ctx.fillStyle = black ? '#000000' : '#ffffff';
        ctx.fillRect(x * scale, y * scale, scale, scale);
    }

    function finder(x, y) {
        for (let dy = 0; dy < 7; dy++) {
            for (let dx = 0; dx < 7; dx++) {
                const xx = x + dx;
                const yy = y + dy;
                const outer = dx === 0 || dx === 6 || dy === 0 || dy === 6;
                const inner = dx >= 2 && dx <= 4 && dy >= 2 && dy <= 4;
                cell(xx, yy, outer || inner);
            }
        }
    }

    finder(quiet, quiet);
    finder(quiet + modules - 7, quiet);
    finder(quiet, quiet + modules - 7);

    for (let i = 8; i < modules - 8; i++) {
        cell(quiet + i, quiet + 6, i % 2 === 0);
        cell(quiet + 6, quiet + i, i % 2 === 0);
    }

    for (let y = 0; y < modules; y++) {
        for (let x = 0; x < modules; x++) {
            const gx = quiet + x;
            const gy = quiet + y;

            const inTL = x < 7 && y < 7;
            const inTR = x >= modules - 7 && y < 7;
            const inBL = x < 7 && y >= modules - 7;
            const timing = x === 6 || y === 6;

            if (inTL || inTR || inBL || timing) continue;

            const on = ((x * 3 + y * 5 + x * y) % 7) < 3;
            cell(gx, gy, on);
        }
    }

    ctx.strokeStyle = '#c62828';
    ctx.lineWidth = Math.max(2, Math.floor(scale * 0.8));
    ctx.strokeRect(0, 0, real, real);

    return canvas.toDataURL('image/png');
}

function populateSelect() {
    templateSelect.innerHTML = '';
    Object.keys(workingMap).forEach((key) => {
        const item = workingMap[key];
        const opt = document.createElement('option');
        opt.value = key;
        opt.textContent = `${item.cert_type} · ${item.rwa_key}`;
        templateSelect.appendChild(opt);
    });

    if (currentKey && workingMap[currentKey]) {
        templateSelect.value = currentKey;
    }
}

function getCurrentItem() {
    return currentKey ? workingMap[currentKey] : null;
}

function updateInfoPanel(item) {
    infoLabel.textContent = item.label || '-';
    infoRwaKey.textContent = item.rwa_key || '-';
    infoFamily.textContent = item.family || '-';
    infoFile.textContent = item.file || '-';
}

function setInputsFromItem(item) {
    inputX.value = item.qr.x;
    inputY.value = item.qr.y;
    inputSize.value = item.qr.size;
}

function updateLiveTexts(item) {
    liveX.textContent = String(item.qr.x);
    liveY.textContent = String(item.qr.y);
    liveSize.textContent = String(item.qr.size);
}

function clampBox(item) {
    const imgW = templateImage.naturalWidth || 0;
    const imgH = templateImage.naturalHeight || 0;
    if (!imgW || !imgH) return;

    item.qr.size = Math.max(20, Math.min(item.qr.size, imgW, imgH));
    item.qr.x = Math.max(0, Math.min(item.qr.x, imgW - item.qr.size));
    item.qr.y = Math.max(0, Math.min(item.qr.y, imgH - item.qr.size));
}

function syncBoxToData() {
    const item = getCurrentItem();
    if (!item) return;

    qrBox.style.left = `${item.qr.x}px`;
    qrBox.style.top = `${item.qr.y}px`;
    qrBox.style.width = `${item.qr.size}px`;
    qrBox.style.height = `${item.qr.size}px`;

    qrImage.src = qrPngDataUri;

    setInputsFromItem(item);
    updateLiveTexts(item);
    updateMapOutput();
    saveMap();
}

function loadTemplate(key) {
    currentKey = key;
    const item = getCurrentItem();
    if (!item) return;

    updateInfoPanel(item);
    templateImage.onload = () => {
        naturalSizeText.textContent = `${templateImage.naturalWidth} × ${templateImage.naturalHeight}`;
        stage.style.width = `${templateImage.naturalWidth}px`;
        stage.style.height = `${templateImage.naturalHeight}px`;
        clampBox(item);
        syncBoxToData();
    };
    templateImage.src = item.image_url + `?v=${Date.now()}`;
}

function applyInputs() {
    const item = getCurrentItem();
    if (!item) return;

    item.qr.x = toInt(inputX.value, item.qr.x);
    item.qr.y = toInt(inputY.value, item.qr.y);
    item.qr.size = toInt(inputSize.value, item.qr.size);

    clampBox(item);
    syncBoxToData();
}

function resetCurrent() {
    if (!currentKey || !TEMPLATE_DATA[currentKey]) return;
    workingMap[currentKey].qr = deepClone(TEMPLATE_DATA[currentKey].qr);
    clampBox(workingMap[currentKey]);
    syncBoxToData();
}

function resetAll() {
    workingMap = deepClone(TEMPLATE_DATA);
    saveMap();
    populateSelect();
    loadTemplate(currentKey || Object.keys(workingMap)[0]);
}

function formatPhpMap(data) {
    const lines = [];
    lines.push('[');
    Object.keys(data).forEach((key) => {
        const item = data[key];
        lines.push(`    '${key}' => [`);
        lines.push(`        'file' => '${item.file}',`);
        lines.push(`        'qr' => ['x' => ${item.qr.x}, 'y' => ${item.qr.y}, 'size' => ${item.qr.size}],`);
        lines.push('    ],');
    });
    lines.push(']');
    return lines.join('\n');
}

function updateMapOutput() {
    mapOutput.value = formatPhpMap(workingMap);
}

function copyMapOutput() {
    mapOutput.select();
    mapOutput.setSelectionRange(0, mapOutput.value.length);
    navigator.clipboard.writeText(mapOutput.value).then(() => {
        copyMapBtn.textContent = 'Copied';
        setTimeout(() => copyMapBtn.textContent = 'Copy PHP Map', 1200);
    }).catch(() => {
        document.execCommand('copy');
        copyMapBtn.textContent = 'Copied';
        setTimeout(() => copyMapBtn.textContent = 'Copy PHP Map', 1200);
    });
}

function pointerPosInStage(clientX, clientY) {
    const rect = stage.getBoundingClientRect();
    const scaleX = (templateImage.naturalWidth || rect.width) / rect.width;
    const scaleY = (templateImage.naturalHeight || rect.height) / rect.height;

    return {
        x: (clientX - rect.left) * scaleX,
        y: (clientY - rect.top) * scaleY,
    };
}

function startDrag(ev) {
    if (ev.target === resizeHandle) return;
    const item = getCurrentItem();
    if (!item) return;

    ev.preventDefault();
    const pos = pointerPosInStage(ev.clientX, ev.clientY);
    dragState = {
        offsetX: pos.x - item.qr.x,
        offsetY: pos.y - item.qr.y,
    };
}

function onDrag(ev) {
    const item = getCurrentItem();
    if (!item || !dragState) return;

    const pos = pointerPosInStage(ev.clientX, ev.clientY);
    item.qr.x = Math.round(pos.x - dragState.offsetX);
    item.qr.y = Math.round(pos.y - dragState.offsetY);

    clampBox(item);
    syncBoxToData();
}

function endDrag() {
    dragState = null;
}

function startResize(ev) {
    const item = getCurrentItem();
    if (!item) return;

    ev.preventDefault();
    ev.stopPropagation();

    const pos = pointerPosInStage(ev.clientX, ev.clientY);
    resizeState = {
        startX: pos.x,
        startY: pos.y,
        startSize: item.qr.size,
    };
}

function onResize(ev) {
    const item = getCurrentItem();
    if (!item || !resizeState) return;

    const pos = pointerPosInStage(ev.clientX, ev.clientY);
    const dx = pos.x - resizeState.startX;
    const dy = pos.y - resizeState.startY;
    const delta = Math.max(dx, dy);

    item.qr.size = Math.round(resizeState.startSize + delta);
    clampBox(item);
    syncBoxToData();
}

function endResize() {
    resizeState = null;
}

templateSelect.addEventListener('change', () => loadTemplate(templateSelect.value));
applyBtn.addEventListener('click', applyInputs);
resetBtn.addEventListener('click', resetCurrent);
resetAllBtn.addEventListener('click', resetAll);
copyMapBtn.addEventListener('click', copyMapOutput);

inputX.addEventListener('change', applyInputs);
inputY.addEventListener('change', applyInputs);
inputSize.addEventListener('change', applyInputs);

qrBox.addEventListener('pointerdown', startDrag);
resizeHandle.addEventListener('pointerdown', startResize);

window.addEventListener('pointermove', (ev) => {
    if (resizeState) return onResize(ev);
    if (dragState) return onDrag(ev);
});
window.addEventListener('pointerup', () => {
    endDrag();
    endResize();
});

qrPngDataUri = makeQrPngDataUri();
loadSavedMap();
populateSelect();
updateMapOutput();
if (currentKey) loadTemplate(currentKey);
</script>
</body>
</html>
