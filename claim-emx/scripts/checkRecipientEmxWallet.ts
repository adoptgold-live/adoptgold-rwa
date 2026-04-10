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
  const apiKey =
    process.env.TONCENTER_API_KEY?.trim() ||
    process.env.TON_API_KEY?.trim() ||
    undefined;

  const master = Address.parse(env('EMX_MASTER_ADDRESS'));
  const recipientOwner = Address.parse(env('ADMIN_ADDRESS'));

  const client = new TonClient({ endpoint, apiKey });

  const tb = new TupleBuilder();
  tb.writeAddress(recipientOwner);

  const res = await client.runMethod(master, 'get_wallet_address', tb.build());
  const wallet = res.stack.readAddress();

  let active = false;
  let rawBalanceUnits: string | null = null;

  try {
    const wd = await client.runMethod(wallet, 'get_wallet_data');
    rawBalanceUnits = wd.stack.readBigNumber().toString();
    active = true;
  } catch {
    active = false;
  }

  console.log(JSON.stringify({
    recipient_owner: recipientOwner.toString(),
    derived_wallet: wallet.toString(),
    wallet_active: active,
    raw_balance_units: rawBalanceUnits
  }, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
