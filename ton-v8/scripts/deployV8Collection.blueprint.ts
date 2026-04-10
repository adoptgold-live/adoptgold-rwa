import 'dotenv/config';
import * as fs from 'fs';
import { Address, beginCell, Cell, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { V8NftCollection, type V8NftCollectionConfig } from '../wrappers/V8NftCollection';

function envRequired(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) throw new Error(`Missing env: ${name}`);
  return v.trim();
}

function buildOffchainContentCell(uri: string): Cell {
  return beginCell().storeUint(0x01, 8).storeBuffer(Buffer.from(uri, 'utf8')).endCell();
}

function loadCompiledCodeCell(path: string): Cell {
  if (!fs.existsSync(path)) throw new Error(`Missing compiled artifact: ${path}`);
  const raw = fs.readFileSync(path, 'utf8');
  const parsed = JSON.parse(raw);
  const hex = parsed?.hex ?? parsed?.codeHex ?? parsed?.code?.hex ?? parsed?.result?.hex ?? '';
  if (!hex || typeof hex !== 'string') throw new Error(`Compiled artifact missing code hex: ${path}`);
  return Cell.fromBoc(Buffer.from(hex, 'hex'))[0];
}

export async function run(provider: NetworkProvider) {
  const owner = Address.parse(envRequired('V8_OWNER'));
  const routerPlaceholder = Address.parse(envRequired('V8_ROUTER_PLACEHOLDER'));
  const royaltyAddress = Address.parse(envRequired('V8_ROYALTY_ADDRESS'));
  const royaltyFactor = Number(envRequired('V8_ROYALTY_FACTOR'));
  const royaltyBase = Number(envRequired('V8_ROYALTY_BASE'));
  const collectionContentUri = envRequired('V8_COLLECTION_CONTENT_URI');
  const deployValueTon = process.env.V8_COLLECTION_DEPLOY_VALUE_TON || '0.25';

  const collectionCode = loadCompiledCodeCell('/var/www/html/public/rwa/ton/build/v8_nft_collection.compiled.json');
  const itemCode = loadCompiledCodeCell('/var/www/html/public/rwa/ton/build/v8_nft_item.compiled.json');

  const config: V8NftCollectionConfig = {
    owner,
    router: routerPlaceholder,
    nextItemIndex: BigInt(process.env.V8_FIRST_ITEM_INDEX || '0'),
    collectionContent: buildOffchainContentCell(collectionContentUri),
    nftItemCode: itemCode,
    royaltyFactor,
    royaltyBase,
    royaltyAddress,
    mintPaused: false,
  };

  const collection = V8NftCollection.createFromConfig(config, collectionCode, 0);

  console.log('V8 Collection address:', collection.address.toString());
  console.log('Owner:', owner.toString());
  console.log('Router placeholder:', routerPlaceholder.toString());
  console.log('Deploy value:', deployValueTon, 'TON');

  await provider.deploy(collection, toNano(deployValueTon));

  console.log('Deploy submitted.');
  console.log(`V8_COLLECTION_ADDRESS=${collection.address.toString()}`);
}
