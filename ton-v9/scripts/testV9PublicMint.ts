import { beginCell, Address, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import * as dotenv from 'dotenv';

dotenv.config();

function mustEnv(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) {
    throw new Error(`ENV_MISSING: ${name}`);
  }
  return v.trim();
}

function buildItemMetadataUrl(base: string, certUid: string): string {
  const cleanBase = base.replace(/\/+$/, '');
  return `${cleanBase}/${certUid}.json`;
}

export async function run(provider: NetworkProvider) {
  const collection = Address.parse(mustEnv('V9_COLLECTION_ADDRESS'));
  const attachTon = process.env.V9_PUBLIC_MIN_ATTACH_TON || '0.48';
  const metadataBase = mustEnv('V9_ITEM_METADATA_BASE_URL');
  const certUid = process.argv[2] || `RCO2C-EMA-${Date.now()}`;
  const queryId = BigInt(Date.now());

  const itemMetadataUrl = buildItemMetadataUrl(metadataBase, certUid);

  const itemContent = beginCell()
    .storeStringTail(itemMetadataUrl)
    .endCell();

  const body = beginCell()
    .storeUint(0x504d494e, 32)
    .storeUint(queryId, 64)
    .storeRef(itemContent)
    .endCell();

  console.log('========================================');
  console.log('V9 PUBLIC MINT TEST');
  console.log('========================================');
  console.log('Collection        :', collection.toString());
  console.log('Attach TON        :', attachTon);
  console.log('Cert UID          :', certUid);
  console.log('Item Metadata URL :', itemMetadataUrl);
  console.log('Query ID          :', queryId.toString());
  console.log('Opcode            : 0x504d494e');
  console.log('----------------------------------------');

  await provider.sender().send({
    to: collection,
    value: toNano(attachTon),
    body,
  });

  console.log('Public mint transaction submitted.');
  console.log('Verify after confirmation:');
  console.log('- collection next_index +1');
  console.log('- nft item deployed');
  console.log('- nft owner = sender wallet');
  console.log('- treasury received primary share');
  console.log('- item content points to full metadata URL');
}
