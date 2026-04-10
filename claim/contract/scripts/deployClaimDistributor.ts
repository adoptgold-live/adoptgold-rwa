import fs from 'fs';
import path from 'path';
import { Address, beginCell, Cell, contractAddress, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';

/**
 * ClaimDistributor v1
 * Locked deploy rules:
 * - prebuilt native BOC only
 * - no blueprint build compile path here
 * - jetton payout only: EMA / EMX / EMS / WEMS / USDT-TON
 * - user gas + fixed 0.10 TON treasury contribution remain business logic outside deploy
 */

function mustEnv(name: string): string {
    const v = process.env[name]?.trim();
    if (!v) {
        throw new Error(`Missing env: ${name}`);
    }
    return v;
}

function parseAddr(name: string): Address {
    return Address.parse(mustEnv(name));
}

/**
 * Storage layout:
 *
 * root:
 *   admin_addr
 *   enabled:uint1
 *   last_hash:uint256
 *   ref vaults_a
 *
 * vaults_a:
 *   treasury_addr
 *   ema_wallet
 *   emx_wallet
 *   ref vaults_b
 *
 * vaults_b:
 *   ems_wallet
 *   wems_wallet
 *   usdt_wallet
 *
 * This avoids BitBuilder overflow.
 */
function buildDataCell() {
    const admin = parseAddr('TON_TREASURY_ADDRESS');

    const treasury = parseAddr('TON_TREASURY_ADDRESS');
    const ema = parseAddr('EMA_WALLET');
    const emx = parseAddr('EMX_WALLET');
    const ems = parseAddr('EMS_WALLET');
    const wems = parseAddr('WEMS_WALLET');
    const usdt = parseAddr('USDT_WALLET');

    const vaultsB = beginCell()
        .storeAddress(ems)
        .storeAddress(wems)
        .storeAddress(usdt)
        .endCell();

    const vaultsA = beginCell()
        .storeAddress(treasury)
        .storeAddress(ema)
        .storeAddress(emx)
        .storeRef(vaultsB)
        .endCell();

    const data = beginCell()
        .storeAddress(admin)
        .storeUint(1, 1)
        .storeUint(0, 256)
        .storeRef(vaultsA)
        .endCell();

    return data;
}

function loadPrebuiltCode(): Cell {
    const bocPath = path.resolve(
        '/var/www/html/public/rwa/claim/contract/build-native/ClaimDistributor.boc'
    );

    if (!fs.existsSync(bocPath)) {
        throw new Error(`Missing prebuilt BOC: ${bocPath}`);
    }

    const boc = fs.readFileSync(bocPath);
    const cells = Cell.fromBoc(boc);

    if (!cells.length) {
        throw new Error(`Invalid BOC: ${bocPath}`);
    }

    return cells[0];
}

export async function run(provider: NetworkProvider) {
    const ui = provider.ui();

    const code = loadPrebuiltCode();
    const data = buildDataCell();

    const init = { code, data };
    const address = contractAddress(0, init);

    ui.write(`Prebuilt code loaded from build-native/ClaimDistributor.boc`);
    ui.write(`ClaimDistributor address: ${address.toString()}`);
    ui.write(`Admin/Treasury: ${mustEnv('TON_TREASURY_ADDRESS')}`);
    ui.write(`EMA_WALLET: ${mustEnv('EMA_WALLET')}`);
    ui.write(`EMX_WALLET: ${mustEnv('EMX_WALLET')}`);
    ui.write(`EMS_WALLET: ${mustEnv('EMS_WALLET')}`);
    ui.write(`WEMS_WALLET: ${mustEnv('WEMS_WALLET')}`);
    ui.write(`USDT_WALLET: ${mustEnv('USDT_WALLET')}`);

    await provider.sender().send({
        to: address,
        value: toNano('0.15'),
        bounce: false,
        init,
        body: beginCell().endCell(),
    });

    ui.write(`Deploy sent. Waiting for deployment...`);
    await provider.waitForDeploy(address);

    ui.write(`DEPLOYED: ${address.toString()}`);
}
