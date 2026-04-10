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
  const owner = Address.parse(env('CLAIM_EMX_ADDRESS'));

  const client = new TonClient({ endpoint, apiKey });

  const tb = new TupleBuilder();
  tb.writeAddress(owner);

  const res = await client.runMethod(master, 'get_wallet_address', tb.build());
  const wallet = res.stack.readAddress();

  try {
    const wd = await client.runMethod(wallet, 'get_wallet_data');
    const balance = wd.stack.readBigNumber();
    const walletOwner = wd.stack.readAddress();
    const walletMaster = wd.stack.readAddress();

    console.log(JSON.stringify({
      wallet: wallet.toString(),
      wallet_active: true,
      raw_balance_units: balance.toString(),
      owner: walletOwner.toString(),
      master: walletMaster.toString()
    }, null, 2));
  } catch (err: any) {
    console.log(JSON.stringify({
      wallet: wallet.toString(),
      wallet_active: false,
      raw_balance_units: null,
      owner: owner.toString(),
      master: master.toString(),
      note: 'Derived wallet not active yet'
    }, null, 2));
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
