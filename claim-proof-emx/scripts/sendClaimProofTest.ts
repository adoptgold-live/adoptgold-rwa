import 'dotenv/config';
import { createHash } from 'node:crypto';
import { Address, beginCell, toNano } from '@ton/core';
import { keyPairFromSeed, sign } from '@ton/crypto';
import { NetworkProvider } from '@ton/blueprint';
import { OP_CLAIM_PROOF } from '../wrappers/ClaimProofEMX';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v.trim();
}

function hexToBytes(hex: string): Buffer {
  const clean = hex.replace(/^0x/i, '').trim();
  if (!/^[0-9a-fA-F]+$/.test(clean)) {
    throw new Error('SIGNER_SECRET_KEY_HEX must be hex');
  }
  if (clean.length !== 64) {
    throw new Error(`SIGNER_SECRET_KEY_HEX must be 32 bytes (64 hex chars), got ${clean.length}`);
  }
  return Buffer.from(clean, 'hex');
}

function getArg(name: string): string | undefined {
  const prefix = `--${name}=`;
  for (const a of process.argv) {
    if (a.startsWith(prefix)) return a.slice(prefix.length);
  }
  return undefined;
}

export async function run(provider: NetworkProvider) {
  const contract = Address.parse(env('CLAIM_PROOF_EMX_ADDRESS'));
  const recipient = Address.parse(getArg('recipient') ?? env('ADMIN_ADDRESS'));

  const seed32 = hexToBytes(env('SIGNER_SECRET_KEY_HEX'));
  const kp = keyPairFromSeed(seed32);

  const queryId = BigInt(getArg('query') ?? '1');
  const claimNonce = BigInt(getArg('nonce') ?? '1');
  const amountUnits = BigInt(getArg('amount_units') ?? '1000000000');
  const validUntil = Number(getArg('valid_until') ?? `${Math.floor(Date.now() / 1000) + 600}`);
  const claimRef = getArg('claim_ref') ?? `CLAIM-PROOF-TEST-${claimNonce.toString()}`;

  const claimRefHashBuf = createHash('sha256').update(claimRef).digest();
  const claimRefHash = BigInt(`0x${claimRefHashBuf.toString('hex')}`);

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

  const signature = sign(tonCheckHash, kp.secretKey);

  const body = beginCell()
    .storeUint(OP_CLAIM_PROOF, 32)
    .storeUint(queryId, 64)
    .storeUint(claimNonce, 64)
    .storeAddress(recipient)
    .storeUint(amountUnits, 128)
    .storeUint(validUntil, 32)
    .storeUint(claimRefHash, 256)
    .storeRef(beginCell().storeBuffer(signature).endCell())
    .endCell();

  console.log('--- CLAIM PROOF SEND PREVIEW ---');
  console.log('Contract:', contract.toString());
  console.log('Recipient owner:', recipient.toString());
  console.log('Query ID:', queryId.toString());
  console.log('Claim nonce:', claimNonce.toString());
  console.log('Amount units:', amountUnits.toString());
  console.log('Claim ref:', claimRef);
  console.log('Valid until:', validUntil);
  console.log('Attach TON:', '0.15');
  console.log('Proof hash:', `0x${Buffer.from(proofHash).toString('hex')}`);

  await provider.sender().send({
    to: contract,
    value: toNano('0.15'),
    body,
  });

  console.log('✅ Claim proof request sent');
}
