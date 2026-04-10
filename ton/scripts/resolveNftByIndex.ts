import 'dotenv/config';
import { Address } from '@ton/core';
import { TonClient4 } from '@ton/ton';
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

async function main() {
  const collectionArg = argValue('collection');
  const itemIndexArg = argValue('item_index');

  const collectionAddress = Address.parse(collectionArg);
  const itemIndex = BigInt(itemIndexArg);

  const rpcUrl = optionalArg(
    'rpc',
    process.env.TON_RPC_URL || process.env.TONCENTER_RPC_URL || 'https://mainnet-v4.tonhubapi.com'
  );

  const client = new TonClient4({ endpoint: rpcUrl });
  const collection = client.open(RwaCollection.createFromAddress(collectionAddress));
  const nftItemAddress = await collection.getNftAddressByIndex(itemIndex);

  process.stdout.write(JSON.stringify({
    ok: true,
    collection_address: collectionAddress.toString(),
    item_index: itemIndex.toString(),
    nft_item_address: nftItemAddress.toString(),
  }));
}

main().catch((err) => {
  console.error('[resolveNftByIndex] fatal:', err);
  process.exit(1);
});
