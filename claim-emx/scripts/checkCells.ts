import 'dotenv/config';
import { Address, beginCell } from '@ton/core';

function must(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v.trim();
}

function envBigInt(name: string, fallback?: string): bigint {
  const raw0 = (process.env[name] ?? fallback ?? '').trim();
  if (!raw0) throw new Error(`Missing env: ${name}`);
  const raw = raw0.toLowerCase();

  if (/^[0-9]+$/.test(raw)) return BigInt(raw);
  if (/^0x[0-9a-f]+$/.test(raw)) return BigInt(raw);
  if (/^[0-9a-f]+$/.test(raw)) return BigInt('0x' + raw);

  throw new Error(`Invalid bigint env ${name}: ${raw0}`);
}

function cellStats(label: string, c: ReturnType<typeof beginCell> extends infer _ ? any : never) {
  const bits = c.bits.length;
  const refs = c.refs.length;
  console.log(`${label}: bits=${bits}, refs=${refs}`);
  if (bits > 1023) {
    throw new Error(`${label} OVERFLOW: ${bits} bits > 1023`);
  }
  if (refs > 4) {
    throw new Error(`${label} OVERFLOW: ${refs} refs > 4`);
  }
}

const admin = Address.parse(must('ADMIN_ADDRESS'));
const treasury = Address.parse(must('TREASURY_ADDRESS'));
const emxMaster = Address.parse(must('EMX_MASTER_ADDRESS'));
const emxWallet = Address.parse('EQCfrqhvHcyBDVt4S2lIoQ7cMYQVWmmen_kZcbm53qTpnbMf');

const signerPublicKey = envBigInt('SIGNER_PUBLIC_KEY');
const minAttached = envBigInt('MIN_ATTACHED', '250000000');
const lastClaimHash = 0n;

const walletRef = beginCell()
  .storeAddress(emxWallet)
  .endCell();

const coreRef = beginCell()
  .storeUint(signerPublicKey, 256)
  .storeCoins(minAttached)
  .storeUint(lastClaimHash, 256)
  .storeAddress(emxMaster)
  .storeRef(walletRef)
  .endCell();

const dataCell = beginCell()
  .storeAddress(admin)
  .storeAddress(treasury)
  .storeUint(0, 1)
  .storeRef(coreRef)
  .endCell();

console.log('--- cell stats ---');
console.log(`walletRef: bits=${walletRef.bits.length}, refs=${walletRef.refs.length}`);
console.log(`coreRef:   bits=${coreRef.bits.length}, refs=${coreRef.refs.length}`);
console.log(`dataCell:  bits=${dataCell.bits.length}, refs=${dataCell.refs.length}`);

if (walletRef.bits.length > 1023 || walletRef.refs.length > 4) {
  throw new Error('walletRef overflow');
}
if (coreRef.bits.length > 1023 || coreRef.refs.length > 4) {
  throw new Error('coreRef overflow');
}
if (dataCell.bits.length > 1023 || dataCell.refs.length > 4) {
  throw new Error('dataCell overflow');
}

console.log('OK: no cell overflow in config layout');
