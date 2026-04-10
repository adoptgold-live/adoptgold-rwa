/**
 * /var/www/html/public/rwa/ton/scripts/buildMintPayload.ts
 * Version: v2.1.0-20260330-op-owner-mint-fixed
 *
 * Purpose:
 * - build real TON BOC payload for RWA Cert collection owner mint
 * - align exactly with wrapper sendOwnerMint() body layout
 *
 * Locked behavior:
 * - opcode = OP_OWNER_MINT (0x4d494e54)
 * - body layout:
 *   uint32 opcode
 *   uint64 query_id
 *   uint64 item_index
 *   address item_owner
 *   ref item_content
 * - wallet transfer recipient = collection address
 * - tx value = RWA_CERT_TX_VALUE_TON
 */

import 'dotenv/config';
import { Address, beginCell, toNano } from '@ton/core';
import { TonClient4 } from '@ton/ton';
import { RwaCollection } from '../wrappers/RwaCollection';

const OP_OWNER_MINT = 0x4d494e54;

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

async function main() {
  const certUid = argValue('cert_uid');
  const owner = Address.parse(argValue('owner'));
  const suffix = argValue('suffix');

  const collectionAddress = Address.parse(envRequired('RWA_CERT_COLLECTION_ADDRESS'));
  const rpcUrl = optionalArg(
    'rpc',
    process.env.TON_RPC_URL || process.env.TONCENTER_RPC_URL || 'https://mainnet-v4.tonhubapi.com'
  );

  const queryId = BigInt(optionalArg('query_id', String(Date.now())));
  const deployAmountTon = optionalArg('deploy_amount', process.env.RWA_CERT_DEPLOY_AMOUNT_TON || '0.05');
  const txValueTon = optionalArg('tx_value', process.env.RWA_CERT_TX_VALUE_TON || '0.30');

  const deployAmount = toNano(deployAmountTon);
  const txValue = toNano(txValueTon);

  const client = new TonClient4({ endpoint: rpcUrl });
  const collection = client.open(RwaCollection.createFromAddress(collectionAddress));
  const data = await collection.getCollectionData();
  const itemIndex = BigInt(data.nextItemIndex.toString());

  const itemContent = beginCell()
    .storeBuffer(Buffer.from(suffix, 'utf8'))
    .endCell();

  const body = beginCell()
    .storeUint(OP_OWNER_MINT, 32)
    .storeUint(queryId, 64)
    .storeUint(itemIndex, 64)
    .storeAddress(owner)
    .storeRef(itemContent)
    .endCell();

  const result = {
    ok: true,
    cert_uid: certUid,
    collection_address: collectionAddress.toString(),
    recipient: collectionAddress.toString(),
    owner: owner.toString(),
    item_index: itemIndex.toString(),
    query_id: queryId.toString(),
    deploy_amount_ton: deployAmountTon,
    deploy_amount_nano: deployAmount.toString(),
    tx_value_ton: txValueTon,
    tx_value_nano: txValue.toString(),
    item_content_suffix: suffix,
    payload_b64: body.toBoc().toString('base64'),
    op_code: OP_OWNER_MINT,
    op_code_hex: '0x4d494e54',
  };

  process.stdout.write(JSON.stringify(result));
}

main().catch((err) => {
  console.error('[buildMintPayload] fatal:', err);
  process.exit(1);
});
