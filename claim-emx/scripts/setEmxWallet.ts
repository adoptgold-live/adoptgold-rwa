import 'dotenv/config';
import { Address, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { ClaimEmx } from '../wrappers/ClaimEmx';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

function getArg(name: string): string | undefined {
  const prefix = `--${name}=`;
  for (const a of process.argv) {
    if (a.startsWith(prefix)) return a.slice(prefix.length);
  }
  return undefined;
}

export async function run(provider: NetworkProvider) {
  const walletArg = getArg('wallet');
  if (!walletArg) {
    throw new Error('Usage: npx blueprint run setEmxWallet --mainnet -- --wallet=EQ...');
  }

  const claimAddress = Address.parse(env('CLAIM_EMX_ADDRESS'));
  const emxWallet = Address.parse(walletArg);

  const contract = provider.open(ClaimEmx.createFromAddress(claimAddress));

  console.log(`ClaimEmx address: ${claimAddress.toString()}`);
  console.log(`Setting EMX wallet: ${emxWallet.toString()}`);

  await contract.sendSetEmxWallet(
    provider.sender(),
    toNano('0.05'),
    emxWallet,
  );

  console.log('✅ EMX wallet set request sent');
}
