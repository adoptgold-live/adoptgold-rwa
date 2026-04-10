import 'dotenv/config';
import * as fs from 'fs';
import { Address, Cell, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { V8MintRouter, type V8MintRouterConfig } from '../wrappers/V8MintRouter';

function envRequired(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) throw new Error(`Missing env: ${name}`);
  return v.trim();
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
  const collection = Address.parse(envRequired('V8_COLLECTION_ADDRESS'));
  const treasury = Address.parse(envRequired('V8_TREASURY_ADDRESS'));
  const deployValueTon = process.env.V8_ROUTER_DEPLOY_VALUE_TON || '0.25';

  const routerCode = loadCompiledCodeCell('/var/www/html/public/rwa/ton/build/v8_mint_router.compiled.json');

  const config: V8MintRouterConfig = {
    owner,
    collection,
    treasury,
    mintPaused: false,
    treasuryContribution: toNano(process.env.V8_TREASURY_CONTRIBUTION_TON || '0.33'),
    publicMinAttach: toNano(process.env.V8_PUBLIC_MIN_ATTACH_TON || '0.50'),
    collectionForwardValue: toNano(process.env.V8_COLLECTION_FORWARD_TON || '0.12'),
  };

  const router = V8MintRouter.createFromConfig(config, routerCode, 0);

  console.log('V8 Router address:', router.address.toString());
  console.log('Collection:', collection.toString());
  console.log('Treasury:', treasury.toString());
  console.log('Deploy value:', deployValueTon, 'TON');

  await provider.deploy(router, toNano(deployValueTon));

  console.log('Deploy submitted.');
  console.log(`V8_ROUTER_ADDRESS=${router.address.toString()}`);
}
