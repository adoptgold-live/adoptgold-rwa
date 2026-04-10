import 'dotenv/config';
import { Address } from '@ton/core';
import { TonClient } from '@ton/ton';
import { ClaimEmx } from '../wrappers/ClaimEmx';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

async function main() {
  const endpoint = env('TON_RPC_ENDPOINT');
  const apiKey = process.env.TONCENTER_API_KEY || process.env.TON_API_KEY || '';
  const claimAddress = Address.parse(env('CLAIM_EMX_ADDRESS'));

  const client = new TonClient({
    endpoint,
    apiKey: apiKey || undefined,
  });

  const contract = client.open(ClaimEmx.createFromAddress(claimAddress));
  const cfg = await contract.getConfig();

  const result = {
    enabled: cfg.enabled,
    emxWallet: cfg.emxWallet ? cfg.emxWallet.toString() : null,
    emxMaster: cfg.emxMaster.toString(),
    checks: {
      enabled_ok: cfg.enabled === true,
      emx_wallet_set: cfg.emxWallet !== null,
      emx_master_set: !!cfg.emxMaster,
    },
  };

  console.log(JSON.stringify(result, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
