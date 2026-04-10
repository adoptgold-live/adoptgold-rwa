/**
 * /var/www/html/public/rwa/ton/scripts/deployRwaCertCollectionV2.ts
 * Version: v2.0.0-20260329-rwa-cert-collection-v2-deploy
 */

import * as dotenv from 'dotenv';
import { NetworkProvider, compile } from '@ton/blueprint';
import { Address, beginCell, Cell, toNano } from '@ton/core';
import { RwaCertCollectionV2 } from '../wrappers/RwaCertCollectionV2';

dotenv.config({ path: '/var/www/secure/.env' });

function envStrict(name: string): string {
  const v = (process.env[name] || '').trim();
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

function parseAddress(name: string, value: string): Address {
  try {
    return Address.parse(value);
  } catch {
    throw new Error(`Invalid ${name}: ${value}`);
  }
}

function buildOffchainContent(url: string): Cell {
  const urlBuffer = Buffer.from(url, 'utf8');
  return beginCell()
    .storeUint(0x01, 8)
    .storeBuffer(urlBuffer)
    .endCell();
}

export async function run(provider: NetworkProvider) {
  const owner = parseAddress('RWA_CERT_COLLECTION_OWNER', envStrict('RWA_CERT_COLLECTION_OWNER'));
  const treasury = parseAddress('RWA_CERT_COLLECTION_TREASURY', envStrict('RWA_CERT_COLLECTION_TREASURY'));
  const royaltyAddress = treasury;

  const royaltyFactor = Number(envStrict('RWA_CERT_ROYALTY_FACTOR'));
  const royaltyBase = Number(envStrict('RWA_CERT_ROYALTY_BASE'));
  const collectionContentUri = envStrict('RWA_CERT_COLLECTION_CONTENT_URI');
  const deployValueTon = (process.env.RWA_CERT_DEPLOY_VALUE_TON || '0.25').trim();

  const collectionCode = await compile('rwa_cert_collection_v2');

  /**
   * IMPORTANT:
   * Replace this with your actual NFT item code cell once finalized.
   * For deploy baseline, we keep a placeholder empty code cell only to establish
   * the collection/admin storage and address.
   */
  const nftItemCode = beginCell().endCell();

  const config = {
    owner,
    nextItemIndex: 0n,
    collectionContent: buildOffchainContent(collectionContentUri),
    nftItemCode,
    royaltyFactor,
    royaltyBase,
    royaltyAddress,
    treasury,
    mintPaused: false,
  };

  const collection = RwaCertCollectionV2.createFromConfig(config, collectionCode);

  console.log('Deploying RWA Cert Collection V2');
  console.log('Address:', collection.address.toString());
  console.log('Owner:', owner.toString());
  console.log('Treasury:', treasury.toString());
  console.log('Collection URI:', collectionContentUri);
  console.log('Royalty:', `${royaltyFactor}/${royaltyBase}`);
  console.log('Deploy value TON:', deployValueTon);

  await provider.deploy(collection, toNano(deployValueTon));

  await provider.waitForDeploy(collection.address);

  console.log('');
  console.log('✅ Deployed');
  console.log('Collection address:', collection.address.toString());
}
