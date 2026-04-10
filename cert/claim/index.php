<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/session-user.php';

$user = session_user();
$uid = $user['id'] ?? 0;

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>RWA Claim</title>
<script src="https://unpkg.com/@tonconnect/ui@latest/dist/tonconnect-ui.min.js"></script>
<style>
body { background:#000;color:#0f0;font-family:monospace;text-align:center;padding:30px }
button { padding:12px 20px;font-size:18px;background:#0f0;color:#000;border:none }
</style>
</head>
<body>

<h1>RWA CLAIM</h1>

<div id="wallet"></div>

<h2 id="balance">Loading...</h2>

<button onclick="claim()">CLAIM TON</button>

<script>
const tonConnectUI = new TON_CONNECT_UI.TonConnectUI({
    manifestUrl: "https://adoptgold.app/tonconnect-manifest.json",
    buttonRootId: "wallet"
});

async function loadBalance() {
    const res = await fetch('/rwa/cert/api/claim.php');
    const data = await res.json();
    document.getElementById('balance').innerText = 
        "Claimable: " + data.claimable_ton + " TON";
}

async function claim() {

    const res = await fetch('/rwa/cert/api/claim-payout.php');
    const data = await res.json();

    if (!data.amount_nano || data.amount_nano === "0") {
        alert("Nothing to claim");
        return;
    }

    const tx = {
        validUntil: Math.floor(Date.now() / 1000) + 300,
        messages: [
            {
                address: data.recipient,
                amount: data.amount_nano,
                payload: btoa(data.memo || "RWA CLAIM")
            }
        ]
    };

    try {
        await tonConnectUI.sendTransaction(tx);

        alert("Claim submitted!");

        await fetch('/rwa/cert/api/claim-confirm.php', { method:'POST' });

        loadBalance();

    } catch (e) {
        console.error(e);
        alert("Transaction cancelled");
    }
}

loadBalance();
</script>

</body>
</html>
