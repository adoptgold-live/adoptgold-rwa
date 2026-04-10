import 'dotenv/config';
import { Address, TonClient4 } from '@ton/ton';
import { toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { V8NftCollection } from '../wrappers/V8NftCollection';

function argValue(name: string): string {
  const flag = `--${name}`;
  const idx = process.argv.indexOf(flag);
  if (idx === -1 || idx + 1 >= process.argv.length) {
    throw new Error(`Missing CLI arg: ${flag}`);
  }
  return String(process.argv[idx + 1]).trim();
}

export async function run(provider: NetworkProvider) {
  const collectionAddr = Address.parse(argValue('collection'));
  const routerAddr = Address.parse(argValue('router'));

  const collection = provider.open(V8NftCollection.createFromAddress(collectionAddr));
  await collection.sendSetRouter(provider.sender(), {
    router: routerAddr,
    value: toNano('0.05'),
  });

  console.log('Router set on collection.');
  console.log('Collection:', collectionAddr.toString());
  console.log('Router:', routerAddr.toString());
}
