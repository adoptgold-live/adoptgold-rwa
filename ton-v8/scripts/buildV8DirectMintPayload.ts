import { beginCell, Address, toNano } from 'ton-core';
import * as dotenv from 'dotenv';

dotenv.config();

function mustEnv(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) throw new Error(`ENV_MISSING: ${name}`);
  return v.trim();
}

const OP_OWNER_MINT = 0x4d494e54;

const collectionRaw = mustEnv('V8_COLLECTION_ADDRESS');
const ownerRaw = mustEnv('V8_OWNER');

const suffix = process.argv[2] || `direct-${Date.now()}.json`;
const queryId = BigInt(Date.now());
const forcedIndex = 0n;

const collection = Address.parse(collectionRaw);
const owner = Address.parse(ownerRaw);

const itemContent = beginCell()
  .storeStringTail(suffix)
  .endCell();

const body = beginCell()
  .storeUint(OP_OWNER_MINT, 32)
  .storeUint(queryId, 64)
  .storeUint(forcedIndex, 64)
  .storeAddress(owner)
  .storeRef(itemContent)
  .endCell();

const payloadB64 = body.toBoc().toString('base64');

console.log(JSON.stringify({
  ok: true,
  mode: 'owner_direct_mint',
  collection_address: collection.toString(),
  owner: owner.toString(),
  suffix,
  payload_b64: payloadB64,
  op_code_hex: '0x' + OP_OWNER_MINT.toString(16)
}, null, 2));
