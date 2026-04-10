import * as path from 'path';
import * as dotenv from 'dotenv';
import { Address, beginCell, Cell, toNano } from '@ton/core';
import { compile, NetworkProvider } from '@ton/blueprint';
import { V10NftCollection } from '../wrappers/V10NftCollection';

dotenv.config({ path: path.join(__dirname, '..', '.env') });

function required(name: string): string {
  const v = process.env[name];
  if (!v) {
    throw new Error(`Missing env: ${name}`);
  }
  return v;
}

function offchainCell(uri: string): Cell {
  return beginCell().storeUint(0x01, 8).storeBuffer(Buffer.from(uri, 'utf8')).endCell();
}

function contentCellFromEnv(): Cell {
  const collectionContent = offchainCell(required('V10_COLLECTION_CONTENT_URI'));
  const commonContent = beginCell()
    .storeBuffer(Buffer.from(required('V10_ITEM_METADATA_BASE_URL') + '/', 'utf8'))
    .endCell();

  return beginCell()
    .storeRef(collectionContent)
    .storeRef(commonContent)
    .endCell();
}

function cfgRefFromEnv(): Cell {
  return beginCell()
    .storeUint(BigInt(required('V10_ROYALTY_FACTOR')), 16)
    .storeUint(BigInt(required('V10_ROYALTY_BASE')), 16)
    .storeAddress(Address.parse(required('V10_ROYALTY_ADDRESS')))
    .storeAddress(Address.parse(required('V10_TREASURY_ADDRESS')))
    .storeUint(BigInt(required('V10_DEFAULT_PAUSED')), 1)
    .storeCoins(toNano(required('V10_MIN_STORAGE_RESERVE_TON')))
    .storeCoins(toNano(required('V10_ITEM_DEPLOY_VALUE_TON')))
    .storeCoins(toNano(required('V10_PUBLIC_MIN_ATTACH_TON')))
    .storeCoins(toNano(required('V10_PRIMARY_TREASURY_TON')))
    .endCell();
}

export async function run(provider: NetworkProvider) {
  const owner = Address.parse(required('V10_OWNER'));
  const collectionCode = await compile('V10NftCollection');
  const itemCode = await compile('V10NftItem');

  const contract = provider.open(
    V10NftCollection.createFromConfig(
      {
        owner,
        nextIndex: 0n,
        collectionContent: contentCellFromEnv(),
        itemCode,
        cfgRef: cfgRefFromEnv()
      },
      collectionCode
    )
  );

  console.log('========================================');
  console.log('V10 DEPLOY COLLECTION');
  console.log('========================================');
  console.log('Owner      :', owner.toString());
  console.log('Treasury   :', required('V10_TREASURY_ADDRESS'));
  console.log('Royalty    :', required('V10_ROYALTY_ADDRESS'));
  console.log('Collection :', contract.address.toString());
  console.log('Content URI:', required('V10_COLLECTION_CONTENT_URI'));
  console.log('Item Base  :', required('V10_ITEM_METADATA_BASE_URL'));
  console.log('========================================');

  await contract.sendDeploy(provider.sender(), toNano('0.20'));
  await provider.waitForDeploy(contract.address);

  console.log('DEPLOYED:', contract.address.toString());
  console.log('Save to .env as V10_COLLECTION_ADDRESS=' + contract.address.toString());
}
