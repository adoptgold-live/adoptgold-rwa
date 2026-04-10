import 'dotenv/config';
import { Address, beginCell } from '@ton/core';
import { keyPairFromSeed, sign } from '@ton/crypto';
import QRCode from 'qrcode-terminal';

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

async function main() {
  const contract = Address.parse(env('CLAIM_EMX_ADDRESS'));
  const recipient = Address.parse(env('ADMIN_ADDRESS'));

  const seed32 = hexToBytes(env('SIGNER_SECRET_KEY_HEX'));
  const kp = keyPairFromSeed(seed32);

  const queryId = 10n;
  const amountUnits = 1000000000n; // 1 EMX
  const validUntil = Math.floor(Date.now() / 1000) + 600;

  const core = beginCell()
    .storeUint(0x434C454D, 32)
    .storeUint(queryId, 64)
    .storeUint(amountUnits, 128)
    .storeAddress(recipient)
    .storeUint(validUntil, 32)
    .endCell();

  const claimHash = core.hash();
  const tonCheckHash = beginCell()
    .storeBuffer(claimHash)
    .endCell()
    .hash();

  const signature = sign(tonCheckHash, kp.secretKey);

  const body = beginCell()
    .storeUint(0x434C454D, 32)
    .storeUint(queryId, 64)
    .storeUint(amountUnits, 128)
    .storeAddress(recipient)
    .storeUint(validUntil, 32)
    .storeRef(beginCell().storeBuffer(signature).endCell())
    .endCell();

  const boc = body.toBoc().toString('base64');
  const link = `ton://transfer/${contract.toString()}?amount=450000000&bin=${encodeURIComponent(boc)}`;

  console.log('\n==============================');
  console.log('✅ CLAIM LINK (BEST METHOD)');
  console.log('==============================\n');
  console.log(link);

  console.log('\n==============================');
  console.log('📱 QR (SCAN WITH TONKEEPER)');
  console.log('==============================\n');
  QRCode.generate(link, { small: true });

  console.log('\n==============================');
  console.log('ℹ️ NOTES');
  console.log('==============================');
  console.log('- Attach TON: 0.45');
  console.log('- Query ID: 10');
  console.log('- If expired → rerun script');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
