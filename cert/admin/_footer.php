<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| POAdo RWA Cert Engine
| Admin Footer Partial
|--------------------------------------------------------------------------
|
| Path:
| /rwa/cert/admin/_footer.php
|
| Purpose
| Shared footer for all admin financial control pages
|
| Global Locks Reflected
| - Royalty = 25%
| - Royalty paid directly to Treasury wallet
| - Distribution handled in dashboard ledger
|
*/

$TREASURY_WALLET = 'UQBdYfGArtoCUmBs5TjYQtfPFfQuGC2Ydbj2pQr3zIlNrDta';
?>

<style>
.poado-admin-footer{
    margin-top:28px;
    border-top:1px solid #6f5b1d;
    padding-top:18px;
    color:#cdb86a;
    font-size:13px;
}

.poado-admin-footer__grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}

.poado-admin-footer__box{
    background:#111;
    border:1px solid #6f5b1d;
    border-radius:10px;
    padding:12px;
}

.poado-admin-footer__title{
    font-weight:700;
    color:#d4af37;
    margin-bottom:6px;
}

.poado-admin-footer__mono{
    font-family:monospace;
    word-break:break-all;
    font-size:12px;
}

.poado-admin-footer__small{
    font-size:12px;
    color:#9c8a50;
}

@media (max-width:640px){
    .poado-admin-footer__grid{
        grid-template-columns:1fr;
    }
}
</style>

<div class="poado-admin-footer">

    <div class="poado-admin-footer__grid">

        <div class="poado-admin-footer__box">
            <div class="poado-admin-footer__title">Royalty Architecture</div>

            <div>Marketplace Royalty: <b>25%</b></div>
            <div class="poado-admin-footer__small">Collected directly to Treasury</div>

            <br>

            <div>Distribution Model:</div>

            <div>• 10% Holder Claim Pool</div>
            <div>• 5% ACE Pool (RK92-EMA weighted)</div>
            <div>• 5% Gold Packet Vault</div>
            <div>• 5% Treasury Retained</div>

        </div>


        <div class="poado-admin-footer__box">
            <div class="poado-admin-footer__title">Treasury Wallet</div>

            <div class="poado-admin-footer__mono">
                <?= htmlspecialchars($TREASURY_WALLET) ?>
            </div>

            <br>

            <div class="poado-admin-footer__small">
                All NFT royalties are paid directly to Treasury.
                Distribution and claims are handled inside
                the RWA Royalty Dashboard ledger system.
            </div>

        </div>

    </div>

</div>