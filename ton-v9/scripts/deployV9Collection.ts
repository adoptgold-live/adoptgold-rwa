import { Address, beginCell, Cell, toNano } from '@ton/core';
import { compile, NetworkProvider } from '@ton/blueprint';
import * as dotenv from 'dotenv';
import { V9NftCollection } from '../wrappers/V9NftCollection';

dotenv.config();

function mustEnv(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) {
    throw new Error(`ENV_MISSING: ${name}`);
  }
  return v.trim();
}

function mustNano(name: string): bigint {
  return toNano(mustEnv(name));
}

export async function run(provider: NetworkProvider) {
  const owner = Address.parse(mustEnv('V9_OWNER'));
  const treasury = Address.parse(mustEnv('V9_TREASURY_ADDRESS'));
  const royaltyAddress = Address.parse(mustEnv('V9_ROYALTY_ADDRESS'));
  const collectionUrl = mustEnv('V9_COLLECTION_CONTENT_URI');

  const royaltyFactor = Number(mustEnv('V9_ROYALTY_FACTOR'));
  const royaltyBase = Number(mustEnv('V9_ROYALTY_BASE'));

  const minStorageReserve = mustNano('V9_MIN_STORAGE_RESERVE_TON');
  const itemDeployValue = mustNano('V9_ITEM_DEPLOY_VALUE_TON');
  const publicMinAttach = mustNano('V9_PUBLIC_MIN_ATTACH_TON');
  const primaryTreasuryShare = mustNano('V9_PRIMARY_TREASURY_TON');

  const collectionCode = await compile('V9NftCollection');
  const itemCode = await compile('V9NftItem');

  const contentCell: Cell = beginCell()
    .storeStringTail(collectionUrl)
    .endCell();

  const collection = V9NftCollection.createFromConfig(
    {
      owner,
      nextIndex: 0n,
      content: contentCell,
      itemCode,
      royaltyFactor,
      royaltyBase,
      royaltyAddress,
      treasury,
      paused: 0,
      minStorageReserve,
      itemDeployValue,
      publicMinAttach,
      primaryTreasuryShare,
    },
    collectionCode,
  );

  console.log('========================================');
  console.log('V9 NFT COLLECTION DEPLOY');
  console.log('========================================');
  console.log('Owner                :', owner.toString());
  console.log('Treasury             :', treasury.toString());
  console.log('Royalty Address      :', royaltyAddress.toString());
  console.log('Collection Address   :', collection.address.toString());
  console.log('Collection URL       :', collectionUrl);
  console.log('Royalty Factor/Base  :', `${royaltyFactor}/${royaltyBase}`);
  console.log('Min Storage Reserve  :', process.env.V9_MIN_STORAGE_RESERVE_TON, 'TON');
  console.log('Item Deploy Value    :', process.env.V9_ITEM_DEPLOY_VALUE_TON, 'TON');
  console.log('Public Min Attach    :', process.env.V9_PUBLIC_MIN_ATTACH_TON, 'TON');
  console.log('Primary Treasury     :', process.env.V9_PRIMARY_TREASURY_TON, 'TON');
  console.log('----------------------------------------');

  const opened = provider.open(collection);
  await opened.sendDeploy(provider.sender(), toNano('0.25'));

  console.log('Deploy submitted.');
  console.log('Save this address to .env as V9_COLLECTION_ADDRESS after confirmation:');
  console.log(collection.address.toString());
}
