import * as path from 'path';
import * as dotenv from 'dotenv';
import { Address, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { V10NftCollection } from '../wrappers/V10NftCollection';

dotenv.config({ path: path.join(__dirname, '..', '.env') });

function required(name: string): string {
  const v = process.env[name];
  if (!v) {
    throw new Error(`Missing env: ${name}`);
  }
  return v;
}

function isLikelyAddress(v: string): boolean {
  return /^(EQ|UQ|kQ|0:)/.test(v);
}

export async function run(provider: NetworkProvider, args: string[] = []) {
  const cleanArgs = (args || []).filter((v) => v && !v.startsWith('--'));

  let certUid = required('V10_DEFAULT_TEST_UID');
  let collectionAddress = required('V10_COLLECTION_ADDRESS');

  if (cleanArgs.length >= 1) {
    if (isLikelyAddress(cleanArgs[0])) {
      collectionAddress = cleanArgs[0];
    } else if (cleanArgs[0] !== 'testV10PublicMint') {
      certUid = cleanArgs[0];
    }
  }

  if (cleanArgs.length >= 2) {
    if (isLikelyAddress(cleanArgs[1])) {
      collectionAddress = cleanArgs[1];
    }
  }

  const address = Address.parse(collectionAddress);
  const contract = provider.open(V10NftCollection.createFromAddress(address));

  console.log('========================================');
  console.log('V10 TEST PUBLIC MINT');
  console.log('========================================');
  console.log('Collection :', address.toString());
  console.log('Item Suffix:', certUid + '.json');
  console.log('Attach TON :', required('V10_PUBLIC_MIN_ATTACH_TON'));
  console.log('Wallet     :', provider.sender().address?.toString() ?? 'TonConnect Sender');
  console.log('========================================');

  await contract.sendPublicMint(provider.sender(), {
    value: toNano(required('V10_PUBLIC_MIN_ATTACH_TON')),
    itemSuffix: certUid + '.json'
  });
}
