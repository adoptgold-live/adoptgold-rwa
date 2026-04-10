import { beginCell, Address, toNano } from 'ton-core';
import { NetworkProvider } from '@ton/blueprint';
import * as dotenv from 'dotenv';

dotenv.config();

function mustEnv(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) throw new Error(`ENV_MISSING: ${name}`);
  return v.trim();
}

const OP_OWNER_MINT = 0x4d494e54;

export async function run(provider: NetworkProvider) {
  const collection = Address.parse(mustEnv('V8_COLLECTION_ADDRESS'));
  const owner = Address.parse(mustEnv('V8_OWNER'));

  const queryId = BigInt(Date.now());
  const forcedIndex = 0n;
  const suffix = `direct-${Date.now()}.json`;

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

  await provider.sender().send({
    to: collection,
    value: toNano('0.30'),
    body,
  });

  console.log('OWNER DIRECT MINT SENT');
}
