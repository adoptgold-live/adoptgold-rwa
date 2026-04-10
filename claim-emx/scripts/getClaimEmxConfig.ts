import 'dotenv/config';
import { Address } from '@ton/core';
import { TonClient } from '@ton/ton';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

async function main() {
  const endpoint = env('TON_API_ENDPOINT');
  const client = new TonClient({ endpoint });

  const address = Address.parse(env('CLAIM_EMX_ADDRESS'));
  const res = await client.runMethod(address, 'get_config');

  const admin = res.stack.readAddress();
  const treasury = res.stack.readAddress();
  const enabled = res.stack.readBigNumber() === 1n;
  const signerPublicKey = res.stack.readBigNumber();
  const minAttached = res.stack.readBigNumber();
  const lastClaimHash = res.stack.readBigNumber();
  const emxMaster = res.stack.readAddress();
  const emxWallet = res.stack.readAddressOpt();

  console.log(JSON.stringify({
    address: address.toString(),
    config: {
      admin: admin.toString(),
      treasury: treasury.toString(),
      enabled,
      signerPublicKey: '0x' + signerPublicKey.toString(16),
      minAttached: minAttached.toString(),
      lastClaimHash: '0x' + lastClaimHash.toString(16),
      emxMaster: emxMaster.toString(),
      emxWallet: emxWallet ? emxWallet.toString() : null
    }
  }, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
