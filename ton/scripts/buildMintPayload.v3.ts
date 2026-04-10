/**
 * /var/www/html/public/rwa/ton/scripts/buildMintPayload.v3.ts
 * Version: v3.1.0-20260330-getter-safe-public-mint
 *
 * Changelog:
 * - fixed Prepare Mint blocker caused by live getter exit code 11
 * - payload build no longer hard-fails when collection getters fail
 * - keeps V3 public mint body schema unchanged
 * - keeps env / CLI values as canonical source of truth
 * - returns getter_warnings for diagnostics instead of aborting
 *
 * Purpose:
 * - build real TON BOC payload for V3 public mint
 * - user pays mint tx
 * - contract forwards 0.33 TON directly to treasury
 *
 * Body layout must match RwaCertCollectionV3.sendPublicMint():
 *   op:uint32
 *   query_id:uint64
 *   item_owner:MsgAddress
 *   item_content:ref
 */

import 'dotenv/config';
import { Address, beginCell, toNano } from '@ton/core';
import { TonClient4 } from '@ton/ton';
import { RwaCertCollectionV3 } from '../wrappers/RwaCertCollectionV3';

const OP_PUBLIC_MINT = 0x504d4e54;

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

function stringifyError(err: unknown): string {
  if (err instanceof Error) {
    return err.stack || err.message || String(err);
  }
  try {
    return JSON.stringify(err);
  } catch {
    return String(err);
  }
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
  const txValueTon = optionalArg('tx_value', process.env.RWA_CERT_TX_VALUE_TON || '0.38');
  const contributionTon = optionalArg('contribution', process.env.RWA_CERT_MINT_CONTRIBUTION_TON || '0.33');

  const txValue = toNano(txValueTon);
  const treasuryContribution = toNano(contributionTon);

  const client = new TonClient4({ endpoint: rpcUrl });
  const collection = client.open(RwaCertCollectionV3.createFromAddress(collectionAddress));

  let itemIndexPreview = 0n;
  let minAttachNano = txValue;
  let treasuryAddress = '';
  let mintPaused = false;
  const getterWarnings: string[] = [];

  try {
    const data = await collection.getCollectionData();
    if (data && data.nextItemIndex != null) {
      itemIndexPreview = BigInt(data.nextItemIndex.toString());
    }
  } catch (err) {
    getterWarnings.push(`getCollectionData failed: ${stringifyError(err)}`);
  }

  try {
    const publicTerms = await collection.getPublicMintTerms();
    if (publicTerms?.minAttach != null) {
      minAttachNano = BigInt(publicTerms.minAttach.toString());
    }
    if (publicTerms?.treasury) {
      treasuryAddress = publicTerms.treasury.toString();
    }
    mintPaused = !!publicTerms?.mintPaused;
  } catch (err) {
    getterWarnings.push(`getPublicMintTerms failed: ${stringifyError(err)}`);
  }

  const itemContent = beginCell()
    .storeBuffer(Buffer.from(suffix, 'utf8'))
    .endCell();

  const body = beginCell()
    .storeUint(OP_PUBLIC_MINT, 32)
    .storeUint(queryId, 64)
    .storeAddress(owner)
    .storeRef(itemContent)
    .endCell();

  const result = {
    ok: true,
    cert_uid: certUid,
    collection_address: collectionAddress.toString(),
    recipient: collectionAddress.toString(),
    owner: owner.toString(),
    item_index_preview: itemIndexPreview.toString(),
    query_id: queryId.toString(),
    treasury_contribution_ton: contributionTon,
    treasury_contribution_nano: treasuryContribution.toString(),
    tx_value_ton: txValueTon,
    tx_value_nano: txValue.toString(),
    min_attach_ton: (Number(minAttachNano) / 1e9).toFixed(9),
    min_attach_nano: minAttachNano.toString(),
    treasury_address: treasuryAddress,
    mint_paused: mintPaused,
    item_content_suffix: suffix,
    payload_b64: body.toBoc().toString('base64'),
    op_code: OP_PUBLIC_MINT,
    op_code_hex: '0x504d4e54',
    getter_warnings: getterWarnings,
  };

  process.stdout.write(JSON.stringify(result));
}

main().catch((err) => {
  console.error('[buildMintPayload.v3] fatal:', err);
  process.exit(1);
});
