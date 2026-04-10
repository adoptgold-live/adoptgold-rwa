/**
 * /var/www/html/public/rwa/ton/scripts/deployCollectionV3.ts
 * Version: v3.0.0-20260330-public-mint-033-ton
 *
 * Purpose:
 * - deploy RWA Cert Collection V3
 * - public mint enabled
 * - 0.33 TON treasury contribution
 * - 25% royalty to treasury
 *
 * Required env:
 * - RWA_CERT_COLLECTION_OWNER
 * - RWA_CERT_COLLECTION_TREASURY
 * - RWA_CERT_COLLECTION_CONTENT_URI
 * - RWA_CERT_ROYALTY_FACTOR
 * - RWA_CERT_ROYALTY_BASE
 * - TON_RPC_URL
 *
 * CLI:
 * --code_boc <path-to-compiled-code.boc>
 * --mnemonic "<space separated words>"
 * --value 0.25
 */

import 'dotenv/config';
import * as fs from 'fs';
import { Address, beginCell, Cell, fromNano, toNano } from '@ton/core';
import { mnemonicToPrivateKey } from '@ton/crypto';
import { TonClient4, WalletContractV4, internal } from '@ton/ton';
import { RwaCertCollectionV3, type RwaCertCollectionV3Config } from '../wrappers/RwaCertCollectionV3';

function argValue(name: string): string {
  const flag = `--${name}`;
  const idx = process.argv.indexOf(flag);
  if (idx === -1 || idx + 1 >= process.argv.length) {
    throw new Error(`Missing CLI arg: ${flag}`);
  }
  return String(process.argv[idx + 1]).trim();
}

function optionalArg(name: string, fallback: string): string {
  const flag = `--${name}`;
  const idx = process.argv.indexOf(flag);
  if (idx === -1 || idx + 1 >= process.argv.length) {
    return fallback;
  }
  return String(process.argv[idx + 1]).trim();
}

function envRequired(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) {
    throw new Error(`Missing env: ${name}`);
  }
  return v.trim();
}

function buildOffchainContentCell(uri: string): Cell {
  const buf = Buffer.from(uri, 'utf8');
  return beginCell()
    .storeUint(0x01, 8)
    .storeBuffer(buf)
    .endCell();
}

async function main() {
  const codeBocPath = argValue('code_boc');
  const mnemonicRaw = argValue('mnemonic');
  const deployValueTon = optionalArg('value', process.env.RWA_CERT_DEPLOY_VALUE_TON || '0.25');

  const rpcUrl = process.env.TON_RPC_URL || 'https://mainnet-v4.tonhubapi.com';
  const owner = Address.parse(envRequired('RWA_CERT_COLLECTION_OWNER'));
  const treasury = Address.parse(envRequired('RWA_CERT_COLLECTION_TREASURY'));
  const royaltyAddress = treasury;
  const royaltyFactor = Number(envRequired('RWA_CERT_ROYALTY_FACTOR'));
  const royaltyBase = Number(envRequired('RWA_CERT_ROYALTY_BASE'));
  const collectionContentUri = envRequired('RWA_CERT_COLLECTION_CONTENT_URI');

  const codeBoc = fs.readFileSync(codeBocPath);
  const code = Cell.fromBoc(codeBoc)[0];
  if (!code) {
    throw new Error('Invalid code BOC');
  }

  const config: RwaCertCollectionV3Config = {
    owner,
    nextItemIndex: BigInt(process.env.RWA_CERT_FIRST_PRODUCTION_ITEM_INDEX || '0'),
    collectionContent: buildOffchainContentCell(collectionContentUri),
    nftItemCode: beginCell().endCell(),
    royaltyFactor,
    royaltyBase,
    royaltyAddress,
    treasury,
    mintPaused: false,
  };

  const collection = RwaCertCollectionV3.createFromConfig(config, code, 0);
  const deployValue = toNano(deployValueTon);

  const words = mnemonicRaw.split(/\s+/).filter(Boolean);
  const keyPair = await mnemonicToPrivateKey(words);

  const client = new TonClient4({ endpoint: rpcUrl });
  const wallet = WalletContractV4.create({ workchain: 0, publicKey: keyPair.publicKey });
  const walletSender = wallet.sender(keyPair.secretKey);
  const walletOpened = client.open(wallet);

  const seqno = await walletOpened.getSeqno();

  await walletOpened.sendTransfer({
    secretKey: keyPair.secretKey,
    seqno,
    messages: [
      internal({
        to: collection.address,
        value: deployValue,
        bounce: false,
        init: collection.init!,
        body: beginCell().endCell(),
      }),
    ],
  });

  process.stdout.write(JSON.stringify({
    ok: true,
    network: 'mainnet',
    deploy_value_ton: deployValueTon,
    wallet_address: wallet.address.toString(),
    collection_address: collection.address.toString(),
    owner: owner.toString(),
    treasury: treasury.toString(),
    royalty_factor: royaltyFactor,
    royalty_base: royaltyBase,
    royalty_address: royaltyAddress.toString(),
    collection_content_uri: collectionContentUri,
    next_item_index: String(config.nextItemIndex),
    seqno,
    note: 'Wait for chain confirmation, then update RWA_CERT_COLLECTION_ADDRESS in env.'
  }));
}

main().catch((err) => {
  console.error('[deployCollectionV3] fatal:', err);
  process.exit(1);
});
