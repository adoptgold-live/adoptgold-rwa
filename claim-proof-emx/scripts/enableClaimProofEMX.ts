import 'dotenv/config';
import { Address, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { ClaimProofEMX } from '../wrappers/ClaimProofEMX';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v.trim();
}

function getArg(name: string): string | undefined {
  const prefix = `--${name}=`;
  for (const a of process.argv) {
    if (a.startsWith(prefix)) return a.slice(prefix.length);
  }
  return undefined;
}

export async function run(provider: NetworkProvider) {
  const enabledArg = getArg('enabled');
  const enabled =
    enabledArg === undefined
      ? true
      : ['1', 'true', 'yes', 'on'].includes(enabledArg.toLowerCase());

  const contractAddress = Address.parse(env('CLAIM_PROOF_EMX_ADDRESS'));
  const contract = provider.open(ClaimProofEMX.createFromAddress(contractAddress));

  console.log(`ClaimProofEMX address: ${contractAddress.toString()}`);
  console.log(`Setting enabled = ${enabled}`);

  await contract.sendSetEnabled(
    provider.sender(),
    toNano('0.05'),
    enabled,
  );

  console.log('✅ Enable request sent');
}
