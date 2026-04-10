import 'dotenv/config';
import fs from 'fs';
import path from 'path';
import { Address, Cell, fromNano, internal, toNano } from '@ton/core';
import { mnemonicToWalletKey } from '@ton/crypto';
import { TonClient4, WalletContractV4 } from '@ton/ton';
import { RwaCollection } from '../wrappers/RwaCollection';

function required(name: string): string {
  const v = process.env[name];
  if (!v || !v.trim()) {
    throw new Error(`Missing required env: ${name}`);
  }
  return v.trim();
}

function optional(name: string, fallback: string): string {
  const v = process.env[name];
  return v && v.trim() ? v.trim() : fallback;
}

function loadCellFromFile(filePath: string): Cell {
  const abs = path.resolve(filePath);
  const raw = fs.readFileSync(abs);
  return Cell.fromBoc(raw)[0];
}

async function waitForSeqno(client: TonClient4, wallet: WalletContractV4, current: number) {
  for (let i = 0; i < 30; i++) {
    await new Promise((resolve) => setTimeout(resolve, 3000));
    const next = await wallet.getSeqno();
    if (next > current) {
      return next;
    }
  }
  throw new Error('Timed out waiting for wallet seqno update');
}

async function main() {
  const root = path.resolve(__dirname, '..');

  const rpcUrl = optional('TON_RPC_URL', 'https://mainnet-v4.tonhubapi.com');
  const mnemonic = required('DEPLOY_MNEMONIC').split(/\s+/);
  const ownerAddress = Address.parse(required('OWNER_ADDRESS'));
  const treasuryAddress = Address.parse(required('TREASURY_ADDRESS'));

  const collectionContentUri = required('COLLECTION_CONTENT_URI');
  const commonContentPrefix = required('COMMON_CONTENT_PREFIX');

  const workchain = Number(optional('WORKCHAIN', '0'));
  const royaltyFactor = Number(optional('ROYALTY_FACTOR', '250'));
  const royaltyBase = Number(optional('ROYALTY_BASE', '1000'));
  const deployValue = toNano(optional('DEPLOY_VALUE_TON', '0.25'));

  const collectionCode = loadCellFromFile(path.join(root, 'build', 'rwa_collection.cell'));
  const itemCode = loadCellFromFile(path.join(root, 'build', 'rwa_item.cell'));

  const key = await mnemonicToWalletKey(mnemonic);
  const client = new TonClient4({ endpoint: rpcUrl });
  const wallet = WalletContractV4.create({ workchain, publicKey: key.publicKey });
  const walletContract = client.open(wallet);

  const collection = RwaCollection.createFromConfig(
    {
      ownerAddress,
      treasuryAddress,
      nextItemIndex: 0n,
      collectionContentUri,
      commonContentPrefix,
      nftItemCode: itemCode,
      royaltyFactor,
      royaltyBase,
    },
    collectionCode,
    workchain,
  );

  const openedCollection = client.open(collection);

  console.log('Workspace root:', root);
  console.log('RPC URL:', rpcUrl);
  console.log('Wallet address:', wallet.address.toString());
  console.log('Collection address:', collection.address.toString());
  console.log('Owner address:', ownerAddress.toString());
  console.log('Treasury address:', treasuryAddress.toString());
  console.log('Royalty:', `${royaltyFactor}/${royaltyBase}`);
  console.log('Deploy value:', fromNano(deployValue), 'TON');

  const seqno = await walletContract.getSeqno();

  await walletContract.sendTransfer({
    secretKey: key.secretKey,
    seqno,
    messages: [
      internal({
        to: collection.address,
        value: deployValue,
        init: collection.init,
        bounce: false,
        body: new Cell(),
      }),
    ],
  });

  console.log('Deploy sent. Waiting for seqno...');
  await waitForSeqno(client, walletContract, seqno);

  console.log('Deploy confirmed.');
  console.log('Collection deployed at:', collection.address.toString());

  try {
    const royalty = await openedCollection.getRoyaltyParams();
    console.log('On-chain royalty params:', royalty.factor, royalty.base, royalty.destination.toString());
  } catch (err) {
    console.warn('Getter check skipped or not yet active:', err);
  }
}

main().catch((err) => {
  console.error('[deploy_collection] fatal:', err);
  process.exit(1);
});
