import 'dotenv/config';
import { keyPairFromSeed, sign, signVerify } from '@ton/crypto';
import { beginCell, Address } from '@ton/core';

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
  const amountUnits = 1000000000n;
  const validUntil = Math.floor(Date.now() / 1000) + 600;

  const core = beginCell()
    .storeUint(0x434C454D, 32)
    .storeUint(queryId, 64)
    .storeUint(amountUnits, 128)
    .storeAddress(recipient)
    .storeUint(validUntil, 32)
    .endCell();

  const hash = core.hash();
  const sig = sign(hash, kp.secretKey);
  const ok = signVerify(hash, sig, kp.publicKey);

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
