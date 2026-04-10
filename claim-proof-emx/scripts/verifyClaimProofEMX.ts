import 'dotenv/config';
import { Address } from '@ton/core';
import { TonClient } from '@ton/ton';
import { ClaimProofEMX } from '../wrappers/ClaimProofEMX';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v.trim();
}

function normHex(hex: string): string {
  return hex.replace(/^0x/i, '').trim().toLowerCase();
}

async function main() {
  const endpoint = env('TON_RPC_ENDPOINT');
  const apiKey = process.env.TONCENTER_API_KEY?.trim() || process.env.TON_API_KEY?.trim() || undefined;
  const claimProofAddress = Address.parse(env('CLAIM_PROOF_EMX_ADDRESS'));
  const expectedSigner = normHex(env('SIGNER_PUBLIC_KEY'));
  const expectedMinAttached = BigInt(env('MIN_ATTACHED'));

  const client = new TonClient({
    endpoint,
    apiKey,
  });

  const contract = client.open(ClaimProofEMX.createFromAddress(claimProofAddress));
  const cfg = await contract.getConfig();
  const constants = await contract.getConstants();

  const result = {
    enabled: cfg.enabled,
    signerPublicKey: cfg.signerPublicKey.toString(16).toLowerCase(),
    minAttached: cfg.minAttached.toString(),
    lastClaimHash: `0x${cfg.lastClaimHash.toString(16)}`,
    admin: cfg.admin.toString(),
    treasury: cfg.treasury.toString(),
    constants: {
      treasuryFee: constants.treasuryFee.toString(),
      contractReserve: constants.contractReserve.toString(),
      contractFloor: constants.contractFloor.toString()
    },
    checks: {
      enabled_ok: cfg.enabled === true,
      signer_ok: cfg.signerPublicKey.toString(16).toLowerCase() === expectedSigner,
      min_attached_ok: cfg.minAttached === expectedMinAttached
    }
  };

  console.log(JSON.stringify(result, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
