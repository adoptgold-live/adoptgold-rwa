import 'dotenv/config';
import { Address, beginCell } from '@ton/core';
import { sign } from '@ton/crypto';
import { NetworkProvider } from '@ton/blueprint';
import { ClaimEmx, OP_CLAIM } from '../wrappers/ClaimEmx';

function env(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

function arg(name: string): string | null {
  const item = process.argv.find((x) => x.startsWith(`--${name}=`));
  return item ? item.slice(name.length + 3) : null;
}

function requiredArg(name: string, fallback?: string | null): string {
  const v = arg(name) ?? fallback ?? null;
  if (!v) throw new Error(`Missing --${name}=...`);
  return v;
}

function buildCore(queryId: bigint, amountUnits: bigint, recipient: Address, validUntil: number) {
  return beginCell()
    .storeUint(OP_CLAIM, 32)
    .storeUint(queryId, 64)
    .storeUint(amountUnits, 128)
    .storeAddress(recipient)
    .storeUint(validUntil, 32)
    .endCell();
}

export async function run(provider: NetworkProvider) {
  const claimAddress = Address.parse(env('CLAIM_EMX_ADDRESS'));
  const senderAddress = provider.sender().address;
  if (!senderAddress) {
    throw new Error('TonConnect sender address missing');
  }

  const recipient = Address.parse(requiredArg('recipient', senderAddress.toString()));
  const amountUnits = BigInt(requiredArg('amount-units', process.env.TEST_AMOUNT_UNITS || '1000000000'));
  const attached = BigInt(requiredArg('attached', process.env.MIN_ATTACHED || '250000000'));
  const validWindow = Number(requiredArg('valid-window', '900'));
  const validUntil = Math.floor(Date.now() / 1000) + validWindow;
  const queryId = BigInt(Date.now());

  const signerSecretHex = env('SIGNER_SECRET_KEY_HEX').replace(/^0x/i, '');
  const signerSecret = Buffer.from(signerSecretHex, 'hex');
  if (signerSecret.length !== 32 && signerSecret.length !== 64) {
    throw new Error('SIGNER_SECRET_KEY_HEX must be 32-byte seed or 64-byte secret');
  }

  const core = buildCore(queryId, amountUnits, recipient, validUntil);
  const signature = sign(core.hash(), signerSecret.slice(0, 32));

  const contract = provider.open(ClaimEmx.createFromAddress(claimAddress));
  await contract.sendClaim(provider.sender(), {
    value: attached,
    queryId,
    amountUnits,
    recipient,
    validUntil,
    signature,
  });

  console.log(JSON.stringify({
    claimAddress: claimAddress.toString(),
    sender: senderAddress.toString(),
    recipient: recipient.toString(),
    queryId: queryId.toString(),
    amountUnits: amountUnits.toString(),
    attached: attached.toString(),
    validUntil,
    coreHashHex: core.hash().toString('hex')
  }, null, 2));
}
