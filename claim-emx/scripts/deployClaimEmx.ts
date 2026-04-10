import 'dotenv/config';
import { Address, toNano } from '@ton/core';
import { compile, NetworkProvider } from '@ton/blueprint';
import { ClaimEmx } from '../wrappers/ClaimEmx';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v.trim();
}

function envBigInt(name: string, fallback?: string): bigint {
  const raw0 = (process.env[name] ?? fallback ?? '').trim();
  if (!raw0) throw new Error(`Missing env: ${name}`);

  const raw = raw0.toLowerCase();

  if (/^[0-9]+$/.test(raw)) {
    return BigInt(raw);
  }

  if (/^0x[0-9a-f]+$/.test(raw)) {
    return BigInt(raw);
  }

  if (/^[0-9a-f]+$/.test(raw)) {
    return BigInt('0x' + raw);
  }

  throw new Error(`Invalid bigint env ${name}: ${raw0}`);
}

export async function run(provider: NetworkProvider) {
  const code = await compile('ClaimEmx');

  const admin = Address.parse(env('ADMIN_ADDRESS'));
  const treasury = Address.parse(env('TREASURY_ADDRESS'));
  const emxMaster = Address.parse(env('EMX_MASTER_ADDRESS'));

  const signerPublicKey = envBigInt('SIGNER_PUBLIC_KEY');
  const minAttached = envBigInt('MIN_ATTACHED', '450000000'); // 0.45 TON

  const contract = ClaimEmx.createFromConfig(
    {
      admin,
      treasury,
      enabled: false,
      signerPublicKey,
      minAttached,
      lastClaimHash: 0n,
      emxMaster,
      emxWallet: null,
    },
    code,
  );

  const opened = provider.open(contract);

  console.log(`ClaimEmx address: ${contract.address.toString()}`);
  console.log(`Admin: ${admin.toString()}`);
  console.log(`Treasury: ${treasury.toString()}`);
  console.log(`EMX master: ${emxMaster.toString()}`);
  console.log(`Signer public key: 0x${signerPublicKey.toString(16)}`);
  console.log(`Min attached: ${minAttached.toString()}`);

  await opened.sendDeploy(provider.sender(), toNano('0.2'));

  console.log('✅ Deploy sent');
}
