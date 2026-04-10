import * as path from 'path';
import * as dotenv from 'dotenv';
import { Address } from '@ton/core';
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

export async function run(provider: NetworkProvider) {
  const address = Address.parse(required('V10_COLLECTION_ADDRESS'));
  const contract = provider.open(V10NftCollection.createFromAddress(address));

  const data = await contract.getCollectionData();
  const royalty = await contract.getRoyaltyParams();

  console.log('========================================');
  console.log('V10 COLLECTION DATA');
  console.log('========================================');
  console.log('Address    :', address.toString());
  console.log('Owner      :', data.ownerAddress.toString());
  console.log('Next Index :', data.nextItemIndex.toString());
  console.log('Royalty    :', `${royalty.factor.toString()}/${royalty.base.toString()}`);
  console.log('Royalty To :', royalty.address.toString());
  console.log('========================================');
}
