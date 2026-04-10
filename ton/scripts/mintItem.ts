import 'dotenv/config';
import fs from 'fs';
import path from 'path';
import { Address, beginCell, Cell, fromNano, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { RwaCollection } from '../wrappers/RwaCollection';

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

function loadCell(filePath: string): Cell {
  return Cell.fromBoc(fs.readFileSync(filePath))[0];
}

function buildIndividualContentCell(itemSuffix: string): Cell {
  return beginCell()
    .storeBuffer(Buffer.from(itemSuffix, 'utf8'))
    .endCell();
}

async function resolveNextIndex(provider: NetworkProvider, collection: RwaCollection): Promise<bigint> {
  const opened = provider.open(collection);
  const data = await opened.getCollectionData();
  return BigInt(data.nextItemIndex.toString());
}

export async function run(provider: NetworkProvider) {
  const certUid = argValue('cert_uid');
  const owner = Address.parse(argValue('owner'));
  const itemSuffix = argValue('suffix');

  const root = path.resolve(__dirname, '..');
  const collectionAddress = Address.parse(envRequired('RWA_CERT_COLLECTION_ADDRESS'));
  const deployAmount = toNano(optionalArg('deploy_amount', '0.05'));
  const txValue = toNano(optionalArg('tx_value', '0.12'));

  const collection = RwaCollection.createFromAddress(collectionAddress);
  const opened = provider.open(collection);

  let itemIndex: bigint;
  const rawIndex = optionalArg('item_index', '');
  if (rawIndex !== '') {
    itemIndex = BigInt(rawIndex);
  } else {
    itemIndex = await resolveNextIndex(provider, collection);
  }

  const individualContent = buildIndividualContentCell(itemSuffix);

  console.error('[mintItem] collection:', collectionAddress.toString());
  console.error('[mintItem] cert_uid:', certUid);
  console.error('[mintItem] item_index:', itemIndex.toString());
  console.error('[mintItem] owner:', owner.toString());
  console.error('[mintItem] suffix:', itemSuffix);
  console.error('[mintItem] tx_value:', fromNano(txValue), 'TON');
  console.error('[mintItem] deploy_amount:', fromNano(deployAmount), 'TON');

  await opened.sendMint(provider.sender(), {
    value: txValue,
    queryId: BigInt(Date.now()),
    itemIndex,
    deployAmount,
    recipient: owner,
    itemContentSuffix: itemSuffix,
  });

  const nftAddress = await opened.getNftAddressByIndex(itemIndex);

  const result = {
    ok: true,
    cert_uid: certUid,
    collection_address: collectionAddress.toString(),
    item_index: Number(itemIndex),
    nft_item_address: nftAddress.toString(),
    owner_address: owner.toString(),
    item_content_suffix: itemSuffix,
    tx_value_ton: fromNano(txValue),
    deploy_amount_ton: fromNano(deployAmount),
    minted_at: new Date().toISOString(),
  };

  process.stdout.write(JSON.stringify(result, null, 2));
}
