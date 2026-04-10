import 'dotenv/config';
import { createHash } from 'node:crypto';
import { keyPairFromSeed, sign, signVerify } from '@ton/crypto';
import { beginCell, Address } from '@ton/core';
import { OP_CLAIM_PROOF } from '../wrappers/ClaimProofEMX';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v.trim();
}

function hexToBuf(hex: string): Buffer {
  const clean = hex.replace(/^0x/i, '').trim();
  if (!/^[0-9a-fA-F]+$/.test(clean)) throw new Error('bad hex');
  return Buffer.from(clean, 'hex');
}

function normHex(hex: string): string {
  return hex.replace(/^0x/i, '').trim().toLowerCase();
}

async function main() {
  const seed = hexToBuf(env('SIGNER_SECRET_KEY_HEX'));
  if (seed.length !== 32) {
    throw new Error(`SIGNER_SECRET_KEY_HEX must be 32 bytes, got ${seed.length}`);
  }

  const kp = keyPairFromSeed(seed);
  const derivedPub = Buffer.from(kp.publicKey).toString('hex').toLowerCase();
  const envPub = normHex(env('SIGNER_PUBLIC_KEY'));

  const recipient = Address.parse(env('ADMIN_ADDRESS'));
  const queryId = 1n;
  const claimNonce = 1n;
  const amountUnits = 1000000000n;
  const validUntil = Math.floor(Date.now() / 1000) + 600;
  const claimRefHash = BigInt(`0x${createHash('sha256').update('CLAIM-PROOF-TEST-1').digest('hex')}`);

  const core = beginCell()
    .storeUint(OP_CLAIM_PROOF, 32)
    .storeUint(queryId, 64)
    .storeUint(claimNonce, 64)
    .storeAddress(recipient)
    .storeUint(amountUnits, 128)
    .storeUint(validUntil, 32)
    .storeUint(claimRefHash, 256)
    .endCell();

  const proofHash = core.hash();
  const tonCheckHash = beginCell()
    .storeBuffer(proofHash)
    .endCell()
    .hash();

  const sig = sign(tonCheckHash, kp.secretKey);
  const ok = signVerify(tonCheckHash, sig, kp.publicKey);

  console.log(JSON.stringify({
    env_public_key: envPub,
    derived_public_key: derivedPub,
    public_key_match: envPub === derivedPub,
    local_signature_verify_ok: ok,
    seed_bytes: seed.length,
    public_key_bytes: kp.publicKey.length,
    secret_key_bytes: kp.secretKey.length
  }, null, 2));
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
