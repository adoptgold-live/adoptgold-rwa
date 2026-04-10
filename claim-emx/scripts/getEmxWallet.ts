import 'dotenv/config';
import { Address, TupleBuilder } from '@ton/core';
import { TonClient } from '@ton/ton';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v.trim();
}

async function main() {
  const endpoint = env('TON_RPC_ENDPOINT');
  const apiKey = process.env.TONCENTER_API_KEY?.trim() || process.env.TON_API_KEY?.trim() || undefined;

  const master = Address.parse(env('EMX_MASTER_ADDRESS'));
  const owner = Address.parse(env('CLAIM_EMX_ADDRESS'));

  const client = new TonClient({
    endpoint,
    apiKey,
  });

  const tb = new TupleBuilder();
  tb.writeAddress(owner);

  const res = await client.runMethod(master, 'get_wallet_address', tb.build());
  const derivedWallet = res.stack.readAddress();

  const out = {
    master: master.toString(),
    owner: owner.toString(),
    derived_wallet: derivedWallet.toString(),
  };

  console.log(JSON.stringify(out, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
