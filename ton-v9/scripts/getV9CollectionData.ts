import { Address } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
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

export async function run(provider: NetworkProvider) {
  const collectionAddr = Address.parse(mustEnv('V9_COLLECTION_ADDRESS'));
  const collection = provider.open(V9NftCollection.createFromAddress(collectionAddr));

  const data = await collection.getCollectionData();
  const royalty = await collection.getRoyaltyParams();
  const state = await collection.getPublicMintState();

  console.log(JSON.stringify({
    collection: collectionAddr.toString(),
    next_index: data.nextIndex.toString(),
    owner: data.owner.toString(),
    royalty_factor: royalty.factor,
    royalty_base: royalty.base,
    royalty_address: royalty.address.toString(),
    treasury: state.treasury.toString(),
    paused: state.paused,
    public_min_attach: state.publicMinAttach.toString(),
    item_deploy_value: state.itemDeployValue.toString(),
    primary_treasury_share: state.primaryTreasuryShare.toString()
  }, null, 2));
}
