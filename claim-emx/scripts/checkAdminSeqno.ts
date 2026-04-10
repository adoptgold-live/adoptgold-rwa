import 'dotenv/config';
import { TonClient, WalletContractV5R1 } from '@ton/ton';
import { mnemonicToPrivateKey } from '@ton/crypto';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

async function main() {
  const client = new TonClient({
    endpoint: env('TON_API_ENDPOINT'),
    apiKey: process.env.TONCENTER_API_KEY || undefined,
  });

  const key = await mnemonicToPrivateKey(env('ADMIN_MNEMONIC').trim().split(/\s+/));
  const wallet = WalletContractV5R1.create({
    workchain: 0,
    publicKey: key.publicKey,
  });

  const opened = client.open(wallet);
  const seqno = await opened.getSeqno();

  console.log(JSON.stringify({
    wallet: wallet.address.toString(),
    seqno
  }, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
