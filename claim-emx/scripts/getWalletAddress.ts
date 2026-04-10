import 'dotenv/config';
import { Address, TupleBuilder } from '@ton/core';
import { TonClient } from '@ton/ton';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

async function main() {
  const endpoint = env('TON_API_ENDPOINT');
  const emxMaster = Address.parse(env('EMX_MASTER'));
  const owner = Address.parse(env('CLAIM_EMX_ADDRESS'));

  const client = new TonClient({ endpoint });

  const tb = new TupleBuilder();
  tb.writeAddress(owner);

  const res = await client.runMethod(emxMaster, 'get_wallet_address', tb.build());
  const wallet = res.stack.readAddress();

  console.log(JSON.stringify({
    emxMaster: emxMaster.toString(),
    owner: owner.toString(),
    wallet: wallet.toString()
  }, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
