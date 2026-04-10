#!/usr/bin/env npx ts-node

/**
 * /var/www/html/public/rwa/ton-v10/scripts/buildMintPayload.v10.ts
 * Version: v1.0.20260331
 *
 * Purpose:
 * - Build the real V10 public mint payload for standalone RWA cert mint-init.
 * - Return JSON only (stdout), suitable for PHP shell_exec/exec integration.
 *
 * Expected output shape:
 * {
 *   ok: true,
 *   recipient,
 *   amount_ton,
 *   amount_nano,
 *   payload_b64,
 *   valid_until,
 *   item_suffix,
 *   item_index,
 *   query_id,
 *   collection_address,
 *   verification_mode
 * }
 *
 * Notes:
 * - Uses V10 collection address from .env or hard fallback.
 * - Uses on-chain collection getter to resolve next item index.
 * - Tries wrapper helper methods first.
 * - Falls back to env-driven body encoding if wrapper helper is absent.
 * - Prints JSON only to stdout.
 */

import * as fs from 'fs';
import * as path from 'path';
import { Address, beginCell, Cell, toNano } from 'ton-core';
import { TonClient4 } from 'ton';
import { V10NftCollection } from '../wrappers/V10NftCollection';

type Json = Record<string, unknown>;

type EnvMap = Record<string, string>;

type BuildArgs = {
  certUid: string;
  itemSuffix: string;
  metadataPath: string;
  queryId: bigint;
  validUntil: number;
  amountTon: string;
  collectionAddress: string;
  rpcUrl: string;
  bodyMode: string;
  bodyOpcode: number;
};

function printAndExit(obj: Json, code = 0): never {
  process.stdout.write(JSON.stringify(obj, null, 2) + '\n');
  process.exit(code);
}

function fail(message: string, extra: Json = {}, code = 1): never {
  printAndExit(
    {
      ok: false,
      error: message,
      ...extra,
    },
    code
  );
}

function loadEnvFile(filePath: string): EnvMap {
  const out: EnvMap = {};
  if (!fs.existsSync(filePath)) {
    return out;
  }
  const raw = fs.readFileSync(filePath, 'utf8');
  for (const line0 of raw.split(/\r?\n/)) {
    const line = line0.trim();
    if (!line || line.startsWith('#') || !line.includes('=')) continue;
    const idx = line.indexOf('=');
    const key = line.slice(0, idx).trim();
    let val = line.slice(idx + 1).trim();
    if (
      (val.startsWith('"') && val.endsWith('"')) ||
      (val.startsWith("'") && val.endsWith("'"))
    ) {
      val = val.slice(1, -1);
    }
    out[key] = val;
  }
  return out;
}

function envGet(env: EnvMap, key: string, fallback = ''): string {
  const v = process.env[key] ?? env[key] ?? fallback;
  return String(v ?? '').trim();
}

function parseCli(argv: string[], env: EnvMap): BuildArgs {
  const args = argv.slice(2);

  let certUid = '';
  let itemSuffix = '';
  let metadataPath = '';
  let queryIdArg = '';
  let validUntilArg = '';
  let amountTon = '';
  let collectionAddress = '';
  let rpcUrl = '';
  let bodyMode = '';
  let bodyOpcode = '';

  const positional: string[] = [];

  for (let i = 0; i < args.length; i += 1) {
    const a = args[i];
    if (!a.startsWith('--')) {
      positional.push(a);
      continue;
    }
    const [k, inlineV] = a.split('=', 2);
    const nextV = inlineV ?? args[i + 1] ?? '';
    const consumeNext = inlineV == null;

    switch (k) {
      case '--cert':
      case '--cert-uid':
        certUid = nextV;
        if (consumeNext) i += 1;
        break;
      case '--suffix':
      case '--item-suffix':
        itemSuffix = nextV;
        if (consumeNext) i += 1;
        break;
      case '--metadata':
      case '--metadata-path':
        metadataPath = nextV;
        if (consumeNext) i += 1;
        break;
      case '--query-id':
        queryIdArg = nextV;
        if (consumeNext) i += 1;
        break;
      case '--valid-until':
        validUntilArg = nextV;
        if (consumeNext) i += 1;
        break;
      case '--amount':
      case '--amount-ton':
        amountTon = nextV;
        if (consumeNext) i += 1;
        break;
      case '--collection':
      case '--collection-address':
        collectionAddress = nextV;
        if (consumeNext) i += 1;
        break;
      case '--rpc':
      case '--rpc-url':
        rpcUrl = nextV;
        if (consumeNext) i += 1;
        break;
      case '--body-mode':
        bodyMode = nextV;
        if (consumeNext) i += 1;
        break;
      case '--body-opcode':
        bodyOpcode = nextV;
        if (consumeNext) i += 1;
        break;
      default:
        fail('UNKNOWN_ARGUMENT', { argument: a });
    }
  }

  if (!certUid && positional[0]) {
    certUid = positional[0];
  }

  certUid = certUid.trim();
  if (!certUid) {
    fail('CERT_UID_REQUIRED');
  }

  itemSuffix =
    (itemSuffix || certUid).trim();

  metadataPath =
    (metadataPath || `${itemSuffix}.json`).trim();

  const queryId =
    queryIdArg && queryIdArg.trim() !== ''
      ? BigInt(queryIdArg.trim())
      : BigInt(Date.now());

  const validUntil =
    validUntilArg && validUntilArg.trim() !== ''
      ? Number(validUntilArg.trim())
      : Math.floor(Date.now() / 1000) + 15 * 60;

  if (!Number.isFinite(validUntil) || validUntil <= 0) {
    fail('INVALID_VALID_UNTIL', { valid_until: validUntilArg });
  }

  amountTon =
    (amountTon || envGet(env, 'V10_PUBLIC_MINT_ATTACH_TON', '0.5')).trim();

  collectionAddress =
    (collectionAddress ||
      envGet(
        env,
        'V10_COLLECTION_ADDRESS',
        'EQBHMH4g3xy-uOJpPN0XGcDhMifdKio_kYWk3uywaXz2aUrY'
      )).trim();

  rpcUrl =
    (rpcUrl ||
      envGet(
        env,
        'TON_RPC_URL',
        envGet(env, 'TONCENTER_API_URL', 'https://mainnet-v4.tonhubapi.com')
      )).trim();

  bodyMode =
    (bodyMode ||
      envGet(env, 'V10_PUBLIC_MINT_BODY_MODE', 'wrapper')).trim().toLowerCase();

  const bodyOpcodeRaw =
    (bodyOpcode || envGet(env, 'V10_PUBLIC_MINT_OPCODE', '0')).trim();

  const bodyOpcodeNum = Number(bodyOpcodeRaw);
  if (!Number.isFinite(bodyOpcodeNum) || bodyOpcodeNum < 0) {
    fail('INVALID_BODY_OPCODE', { body_opcode: bodyOpcodeRaw });
  }

  return {
    certUid,
    itemSuffix,
    metadataPath,
    queryId,
    validUntil,
    amountTon,
    collectionAddress,
    rpcUrl,
    bodyMode,
    bodyOpcode: bodyOpcodeNum >>> 0,
  };
}

function cellToB64(cell: Cell): string {
  return cell.toBoc({ idx: false }).toString('base64');
}

function buildFallbackBody(args: {
  bodyMode: string;
  bodyOpcode: number;
  queryId: bigint;
  itemIndex: bigint;
  itemSuffix: string;
  metadataPath: string;
}): Cell {
  const m = args.bodyMode;

  if (m === 'wrapper') {
    fail('WRAPPER_HELPER_NOT_AVAILABLE', {
      hint: 'Set V10_PUBLIC_MINT_BODY_MODE to a concrete fallback mode or add wrapper helper support.',
    });
  }

  const b = beginCell();

  if (args.bodyOpcode > 0) {
    b.storeUint(args.bodyOpcode, 32);
  }

  switch (m) {
    case 'opcode_queryid_index_suffix':
      b.storeUint(args.queryId, 64);
      b.storeUint(args.itemIndex, 64);
      b.storeStringTail(args.itemSuffix);
      break;

    case 'opcode_queryid_index_metadata':
      b.storeUint(args.queryId, 64);
      b.storeUint(args.itemIndex, 64);
      b.storeStringTail(args.metadataPath);
      break;

    case 'opcode_queryid_suffix':
      b.storeUint(args.queryId, 64);
      b.storeStringTail(args.itemSuffix);
      break;

    case 'opcode_queryid_metadata':
      b.storeUint(args.queryId, 64);
      b.storeStringTail(args.metadataPath);
      break;

    case 'opcode_index_suffix':
      b.storeUint(args.itemIndex, 64);
      b.storeStringTail(args.itemSuffix);
      break;

    case 'opcode_index_metadata':
      b.storeUint(args.itemIndex, 64);
      b.storeStringTail(args.metadataPath);
      break;

    case 'suffix_only':
      b.storeStringTail(args.itemSuffix);
      break;

    case 'metadata_only':
      b.storeStringTail(args.metadataPath);
      break;

    default:
      fail('UNSUPPORTED_BODY_MODE', {
        body_mode: m,
        supported_modes: [
          'wrapper',
          'opcode_queryid_index_suffix',
          'opcode_queryid_index_metadata',
          'opcode_queryid_suffix',
          'opcode_queryid_metadata',
          'opcode_index_suffix',
          'opcode_index_metadata',
          'suffix_only',
          'metadata_only',
        ],
      });
  }

  return b.endCell();
}

async function resolveNextItemIndex(client: TonClient4, collectionAddr: Address): Promise<bigint> {
  const block = await client.getLastBlock();
  const collection = client.open(V10NftCollection.createFromAddress(collectionAddr)) as any;

  const getterNames = [
    'getCollectionData',
    'getData',
    'get_collection_data',
  ];

  let lastErr: unknown = null;

  for (const getterName of getterNames) {
    try {
      if (typeof collection[getterName] !== 'function') {
        continue;
      }
      const res = await collection[getterName](block.last.seqno);
      if (res && typeof res === 'object') {
        const candidates = [
          (res as any).nextItemIndex,
          (res as any).next_index,
          (res as any).nextIndex,
          (res as any).itemIndex,
        ];
        for (const c of candidates) {
          if (typeof c === 'bigint') return c;
          if (typeof c === 'number' && Number.isFinite(c)) return BigInt(c);
          if (typeof c === 'string' && c.trim() !== '' && /^[0-9]+$/.test(c.trim())) {
            return BigInt(c.trim());
          }
        }
      }
    } catch (e) {
      lastErr = e;
    }
  }

  fail('GET_COLLECTION_NEXT_INDEX_FAILED', {
    collection_address: collectionAddr.toString(),
    detail: lastErr instanceof Error ? lastErr.message : String(lastErr ?? 'unknown'),
  });
}

async function buildWithWrapperHelper(collectionAddr: Address, itemIndex: bigint, args: BuildArgs): Promise<Cell | null> {
  const anyWrapper = V10NftCollection as any;

  const staticMethodNames = [
    'buildPublicMintBody',
    'createPublicMintBody',
    'publicMintBody',
    'getPublicMintBody',
    'buildMintBody',
  ];

  for (const methodName of staticMethodNames) {
    if (typeof anyWrapper[methodName] !== 'function') {
      continue;
    }
    const body = await anyWrapper[methodName]({
      collectionAddress: collectionAddr,
      itemIndex,
      itemSuffix: args.itemSuffix,
      metadataPath: args.metadataPath,
      queryId: args.queryId,
      certUid: args.certUid,
      validUntil: args.validUntil,
    });

    if (body instanceof Cell) {
      return body;
    }
  }

  const instance = (V10NftCollection as any).createFromAddress
    ? (V10NftCollection as any).createFromAddress(collectionAddr)
    : null;

  const instanceMethodNames = [
    'buildPublicMintBody',
    'createPublicMintBody',
    'publicMintBody',
    'getPublicMintBody',
    'buildMintBody',
  ];

  if (instance) {
    for (const methodName of instanceMethodNames) {
      if (typeof instance[methodName] !== 'function') {
        continue;
      }
      const body = await instance[methodName]({
        itemIndex,
        itemSuffix: args.itemSuffix,
        metadataPath: args.metadataPath,
        queryId: args.queryId,
        certUid: args.certUid,
        validUntil: args.validUntil,
      });
      if (body instanceof Cell) {
        return body;
      }
    }
  }

  return null;
}

async function main(): Promise<void> {
  const scriptDir = __dirname;
  const rootDir = path.resolve(scriptDir, '..');
  const env = {
    ...loadEnvFile(path.join(rootDir, '.env')),
  };

  const args = parseCli(process.argv, env);

  let collectionAddr: Address;
  try {
    collectionAddr = Address.parse(args.collectionAddress);
  } catch (e) {
    fail('INVALID_COLLECTION_ADDRESS', {
      collection_address: args.collectionAddress,
      detail: e instanceof Error ? e.message : String(e),
    });
  }

  let amountNano: bigint;
  try {
    amountNano = toNano(args.amountTon);
  } catch (e) {
    fail('INVALID_AMOUNT_TON', {
      amount_ton: args.amountTon,
      detail: e instanceof Error ? e.message : String(e),
    });
  }

  const client = new TonClient4({
    endpoint: args.rpcUrl,
  });

  const itemIndex = await resolveNextItemIndex(client, collectionAddr);

  let body: Cell | null = null;

  // FINAL CONTRACT-EXACT BODY:
  // op_public_mint (0x504d494e)
  // query_id:uint64
  // ref nft_content = offchain content cell with metadata URL
  const metadataUrl = `https://adoptgold.app/${String(args.metadataPath).replace(/^\/+/, '')}`;

  const nftContent = beginCell()
    .storeUint(1, 8)
    .storeStringTail(metadataUrl)
    .endCell();

  body = beginCell()
    .storeUint(0x504d494e, 32)
    .storeUint(args.queryId, 64)
    .storeRef(nftContent)
    .endCell();

  const payloadB64 = cellToB64(body);

  printAndExit({
    ok: true,
    verification_mode: 'v10_public_mint',
    cert_uid: args.certUid,
    recipient: collectionAddr.toString(),
    amount_ton: args.amountTon,
    amount_nano: amountNano.toString(),
    payload_b64: payloadB64,
    valid_until: args.validUntil,
    item_suffix: args.itemSuffix,
    metadata_path: args.metadataPath,
    item_index: itemIndex.toString(),
    query_id: args.queryId.toString(),
    collection_address: collectionAddr.toString(),
    body_mode: args.bodyMode,
    body_opcode: args.bodyOpcode,
    rpc_url: args.rpcUrl,
  });
}

main().catch((e) => {
  fail('BUILD_MINT_PAYLOAD_FAILED', {
    detail: e instanceof Error ? e.message : String(e),
    stack: e instanceof Error ? e.stack : undefined,
  });
});
