import 'dotenv/config';
import fs from 'fs';
import path from 'path';
import { Address, Cell, fromNano, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { RwaCollection } from '../wrappers/RwaCollection';

function required(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) {
    throw new Error(`Missing required env: ${name}`);
  }
  return v.trim();
}

function optional(name: string, fallback: string): string {
  const v = process.env[name];
  return v && v.trim() ? v.trim() : fallback;
}

function loadCell(filePath: string): Cell {
  return Cell.fromBoc(fs.readFileSync(filePath))[0];
}

export async function run(provider: NetworkProvider) {
  const root = path.resolve(__dirname, '..');

  const ownerAddress = Address.parse(required('OWNER_ADDRESS'));
  const treasuryAddress = Address.parse(required('TREASURY_ADDRESS'));
  const collectionContentUri = required('COLLECTION_CONTENT_URI');
  const commonContentPrefix = required('COMMON_CONTENT_PREFIX');
  const royaltyFactor = Number(optional('ROYALTY_FACTOR', '250'));
  const royaltyBase = Number(optional('ROYALTY_BASE', '1000'));
  const deployValue = toNano(optional('DEPLOY_VALUE_TON', '0.25'));

  const collectionCode = loadCell(path.join(root, 'build', 'rwa_collection.cell'));
  const itemCode = loadCell(path.join(root, 'build', 'rwa_item.cell'));

  const contract = RwaCollection.createFromConfig(
    {
      ownerAddress,
      treasuryAddress,
      nextItemIndex: 0n,
      collectionContentUri,
      commonContentPrefix,
      nftItemCode: itemCode,
      royaltyFactor,
      royaltyBase,
    },
    collectionCode,
    0,
  );

  console.log('Collection address:', contract.address.toString());
  console.log('Owner:', ownerAddress.toString());
  console.log('Treasury:', treasuryAddress.toString());
  console.log('Royalty:', `${royaltyFactor}/${royaltyBase}`);
  console.log('Deploy value:', fromNano(deployValue), 'TON');

  await provider.deploy(contract, deployValue);

  const opened = provider.open(contract);
  const royalty = await opened.getRoyaltyParams();
  console.log('royalty_params():', royalty.factor, royalty.base, royalty.destination.toString());
}
