import 'dotenv/config';
import { Address, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { ClaimEmx } from '../wrappers/ClaimEmx';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

function arg(name: string): string | null {
  const item = process.argv.find((x) => x.startsWith(`--${name}=`));
  return item ? item.slice(name.length + 3) : null;
}

export async function run(provider: NetworkProvider) {
  const claimAddress = Address.parse(env('CLAIM_EMX_ADDRESS'));
  const amount = BigInt(arg('amount') || env('SWEEP_AMOUNT'));
  const contract = provider.open(ClaimEmx.createFromAddress(claimAddress));

  await contract.sendSweepTon(provider.sender(), {
    value: toNano('0.05'),
    queryId: BigInt(Date.now()),
    amount,
  });

  console.log('OP_SWEEP_TON sent:', amount.toString());
}
