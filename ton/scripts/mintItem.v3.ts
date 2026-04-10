/**
 * /var/www/html/public/rwa/ton/scripts/mintItem.v3.ts
 * Version: v3.0.0-20260330-public-mint-033-ton
 *
 * Purpose:
 * - local/dev/admin helper to exercise V3 public mint body from a wallet
 * - mainly for verification tooling after deploy
 *
 * CLI:
 * --collection <collection-address>
 * --owner <owner-address>
 * --suffix <item suffix>
 * --query_id <uint64-ish string>
 * --tx_value 0.38
 */

import 'dotenv/config';
import { Address, beginCell, fromNano, toNano } from '@ton/core';
import { TonClient4, WalletContractV4, internal } from '@ton/ton';
import { mnemonicToPrivateKey } from '@ton/crypto';
import { RwaCertCollectionV3, OP_PUBLIC_MINT } from '../wrappers/RwaCertCollectionV3';

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

async function main() {
  const rpcUrl = process.env.TON_RPC_URL || 'https://mainnet-v4.tonhubapi.com';
  const collectionAddress = Address.parse(argValue('collection'));
  const owner = Address.parse(argValue('owner'));
  const suffix = argValue('suffix');
  const queryId = BigInt(optionalArg('query_id', String(Date.now())));
  const txValueTon = optionalArg('tx_value', process.env.RWA_CERT_TX_VALUE_TON || '0.38');
  const mnemonicRaw = argValue('mnemonic');

  const client = new TonClient4({ endpoint: rpcUrl });
  const collection = client.open(RwaCertCollectionV3.createFromAddress(collectionAddress));

  const itemContent = beginCell()
    .storeBuffer(Buffer.from(suffix, 'utf8'))
    .endCell();

  const body = beginCell()
    .storeUint(OP_PUBLIC_MINT, 32)
    .storeUint(queryId, 64)
    .storeAddress(owner)
    .storeRef(itemContent)
    .endCell();

  const words = mnemonicRaw.split(/\s+/).filter(Boolean);
  const keyPair = await mnemonicToPrivateKey(words);
  const wallet = WalletContractV4.create({ workchain: 0, publicKey: keyPair.publicKey });
  const walletOpened = client.open(wallet);
  const seqno = await walletOpened.getSeqno();

  await walletOpened.sendTransfer({
    secretKey: keyPair.secretKey,
    seqno,
    messages: [
      internal({
        to: collection.address,
        value: toNano(txValueTon),
        bounce: true,
        body,
      }),
    ],
  });

  process.stdout.write(JSON.stringify({
    ok: true,
    collection_address: collection.address.toString(),
    sender_wallet: wallet.address.toString(),
    owner: owner.toString(),
    suffix,
    query_id: queryId.toString(),
    tx_value_ton: txValueTon,
    tx_value_nano: toNano(txValueTon).toString(),
    note: 'Public mint transaction sent. Wait for chain confirmation.'
  }));
}

main().catch((err) => {
  console.error('[mintItem.v3] fatal:', err);
  process.exit(1);
});
