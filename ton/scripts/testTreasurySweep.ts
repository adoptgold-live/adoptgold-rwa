/**
 * /var/www/html/public/rwa/ton/scripts/testTreasurySweep.ts
 * Version: v2.1.0-20260329-blueprint-tonkeeper-wdth-fixed
 *
 * Purpose:
 * - Test whether the deployed RWA Cert Collection contract supports owner treasury sweep
 * - Use Blueprint sender / Tonkeeper signing (NO mnemonic in script)
 * - Measure sender / collection / treasury balances before and after
 * - Classify whether sweep is supported, unsupported, or inconclusive
 *
 * Fixes:
 * - use correct withdraw opcode WDTH = 0x57445448
 * - use correct body order: op -> query_id -> treasury_addr -> req_amount
 * - improve interpretation output
 */

import * as dotenv from 'dotenv';
import { NetworkProvider } from '@ton/blueprint';
import { Address, beginCell, fromNano, toNano, Cell } from '@ton/core';
import { TonClient4 } from '@ton/ton';

dotenv.config({ path: '/var/www/secure/.env' });

function envStrict(name: string): string {
  const v = (process.env[name] || '').trim();
  if (!v) throw new Error(`Missing env: ${name}`);
  return v;
}

function envMaybe(name: string, def = ''): string {
  return (process.env[name] || def).trim();
}

function parseAddress(name: string, value: string): Address {
  try {
    return Address.parse(value);
  } catch {
    throw new Error(`Invalid ${name}: ${value}`);
  }
}

async function sleep(ms: number): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

async function getBalance(client: TonClient4, address: Address): Promise<bigint> {
  const last = await client.getLastBlock();
  const seqno = last.last.seqno;
  const res = await client.getAccountLite(seqno, address);
  return BigInt(res.account.balance.coins);
}

export async function run(provider: NetworkProvider): Promise<void> {
  const rpcUrl = envMaybe('TON_RPC_URL', 'https://mainnet-v4.tonhubapi.com');
  const collectionAddress = parseAddress(
    'RWA_CERT_COLLECTION_ADDRESS',
    envStrict('RWA_CERT_COLLECTION_ADDRESS')
  );
  const treasuryAddress = parseAddress(
    'RWA_CERT_COLLECTION_TREASURY',
    envStrict('RWA_CERT_COLLECTION_TREASURY')
  );
  const ownerAddress = parseAddress(
    'RWA_CERT_COLLECTION_OWNER',
    envStrict('RWA_CERT_COLLECTION_OWNER')
  );

  const sweepAmountTon = envMaybe('RWA_CERT_SWEEP_TEST_AMOUNT_TON', '0.03');
  const attachedTon = envMaybe('RWA_CERT_SWEEP_ATTACHED_TON', '0.05');

  const WITHDRAW_OPCODE = 0x57445448; // "WDTH"
  const QUERY_ID = BigInt(Date.now());

  const sender = provider.sender();
  if (!sender.address) {
    throw new Error('Blueprint sender has no address. Connect Tonkeeper / wallet first.');
  }

  const senderAddress = Address.parse(sender.address.toString());
  const client = new TonClient4({ endpoint: rpcUrl });

  console.log('=== RWA Cert Treasury Sweep Test ===');
  console.log('RPC URL:', rpcUrl);
  console.log('Sender:', senderAddress.toString());
  console.log('Owner:', ownerAddress.toString());
  console.log('Collection:', collectionAddress.toString());
  console.log('Treasury:', treasuryAddress.toString());
  console.log('Sweep amount TON:', sweepAmountTon);
  console.log('Attached TON:', attachedTon);
  console.log('Opcode:', '0x' + WITHDRAW_OPCODE.toString(16), '(WDTH)');
  console.log('');

  if (!senderAddress.equals(ownerAddress)) {
    console.log('⚠️ WARNING: Connected sender is NOT the configured collection owner.');
    console.log('Owner-only withdraw will likely fail.');
    console.log('');
  }

  const senderBalanceBefore = await getBalance(client, senderAddress);
  const collectionBalanceBefore = await getBalance(client, collectionAddress);
  const treasuryBalanceBefore = await getBalance(client, treasuryAddress);

  console.log('Sender balance before:', fromNano(senderBalanceBefore));
  console.log('Collection balance before:', fromNano(collectionBalanceBefore));
  console.log('Treasury balance before:', fromNano(treasuryBalanceBefore));

  const body: Cell = beginCell()
    .storeUint(WITHDRAW_OPCODE, 32)
    .storeUint(QUERY_ID, 64)
    .storeAddress(treasuryAddress)
    .storeCoins(toNano(sweepAmountTon))
    .endCell();

  console.log('');
  console.log('Requesting wallet signature...');
  console.log('Requested sweep TON:', sweepAmountTon);
  console.log('Attached TON for call:', attachedTon);
  console.log('Approve in Tonkeeper if prompted.');

  await sender.send({
    to: collectionAddress,
    value: toNano(attachedTon),
    body,
  });

  console.log('Transaction submitted by sender.');
  console.log('Waiting for chain state to settle...');
  await sleep(15000);

  const senderBalanceAfter = await getBalance(client, senderAddress);
  const collectionBalanceAfter = await getBalance(client, collectionAddress);
  const treasuryBalanceAfter = await getBalance(client, treasuryAddress);

  console.log('');
  console.log('Sender balance after:', fromNano(senderBalanceAfter));
  console.log('Collection balance after:', fromNano(collectionBalanceAfter));
  console.log('Treasury balance after:', fromNano(treasuryBalanceAfter));

  const deltaSender = senderBalanceAfter - senderBalanceBefore;
  const deltaCollection = collectionBalanceAfter - collectionBalanceBefore;
  const deltaTreasury = treasuryBalanceAfter - treasuryBalanceBefore;

  console.log('');
  console.log('Sender delta TON:', fromNano(deltaSender));
  console.log('Collection delta TON:', fromNano(deltaCollection));
  console.log('Treasury delta TON:', fromNano(deltaTreasury));

  console.log('');
  console.log('=== Interpretation ===');

  if (deltaTreasury >= toNano(sweepAmountTon)) {
    console.log('✅ SWEEP SUPPORTED');
    console.log('Treasury increased by the requested amount or more.');
    console.log('Collection withdraw path executed.');
    return;
  }

  if (deltaTreasury > 0n && deltaTreasury < toNano(sweepAmountTon)) {
    console.log('⚠️ PARTIAL / UNEXPECTED RESULT');
    console.log('Treasury increased, but not by the full requested amount.');
    console.log('Check contract logic, reserve rules, and RPC timing.');
    return;
  }

  if (deltaTreasury === 0n) {
    console.log('❌ SWEEP FAILED');
    console.log('Treasury did not increase.');
    console.log('Most likely: unauthorized sender, opcode/body mismatch, or contract path rejected.');
    return;
  }

  console.log('⚠️ INCONCLUSIVE');
  console.log('Check owner wallet, opcode/body, contract logic, and RPC delay.');
}
