/**
 * /var/www/html/public/rwa/ton/wrappers/RwaCertCollectionV3.ts
 * Version: v3.1.0-20260330-public-mint-033-ton-final
 *
 * Purpose:
 * - V3 public mint collection wrapper
 * - user public mint supported
 * - fixed 0.33 TON treasury contribution enforced by contract
 * - owner/admin controls retained
 */

import {
  Address,
  beginCell,
  Cell,
  contractAddress,
  Contract,
  ContractProvider,
  Sender,
  SendMode,
  toNano,
} from '@ton/core';

export const OP_OWNER_MINT = 0x4d494e54;       // "MINT"
export const OP_PUBLIC_MINT = 0x504d4e54;      // "PMNT"
export const OP_SET_OWNER = 0x4f574e52;        // "OWNR"
export const OP_SET_TREASURY = 0x54525359;     // "TRSY"
export const OP_SET_CONTENT = 0x434f4e54;      // "CONT"
export const OP_WITHDRAW = 0x57445448;         // "WDTH"
export const OP_SET_MINT_PAUSED = 0x50415553;  // "PAUS"

export type RwaCertCollectionV3Config = {
  owner: Address;
  nextItemIndex: bigint;
  collectionContent: Cell;
  nftItemCode: Cell;
  royaltyFactor: number;
  royaltyBase: number;
  royaltyAddress: Address;
  treasury: Address;
  mintPaused: boolean;
};

export function rwaCertCollectionV3ConfigToCell(config: RwaCertCollectionV3Config): Cell {
  return beginCell()
    .storeAddress(config.owner)
    .storeUint(config.nextItemIndex, 64)
    .storeRef(config.collectionContent)
    .storeRef(config.nftItemCode)
    .storeUint(config.royaltyFactor, 16)
    .storeUint(config.royaltyBase, 16)
    .storeAddress(config.royaltyAddress)
    .storeAddress(config.treasury)
    .storeBit(config.mintPaused)
    .endCell();
}

export class RwaCertCollectionV3 implements Contract {
  constructor(
    readonly address: Address,
    readonly init?: { code: Cell; data: Cell },
  ) {}

  static createFromAddress(address: Address) {
    return new RwaCertCollectionV3(address);
  }

  static createFromConfig(config: RwaCertCollectionV3Config, code: Cell, workchain = 0) {
    const data = rwaCertCollectionV3ConfigToCell(config);
    const init = { code, data };
    return new RwaCertCollectionV3(contractAddress(workchain, init), init);
  }

  async sendDeploy(provider: ContractProvider, via: Sender, value: bigint) {
    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body: beginCell().endCell(),
    });
  }

  async sendSetOwner(provider: ContractProvider, via: Sender, params: {
    queryId?: bigint;
    newOwner: Address;
    value?: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_SET_OWNER, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeAddress(params.newOwner)
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.05'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async sendSetTreasury(provider: ContractProvider, via: Sender, params: {
    queryId?: bigint;
    treasury: Address;
    value?: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_SET_TREASURY, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeAddress(params.treasury)
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.05'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async sendSetContent(provider: ContractProvider, via: Sender, params: {
    queryId?: bigint;
    content: Cell;
    value?: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_SET_CONTENT, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeRef(params.content)
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.05'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async sendWithdraw(provider: ContractProvider, via: Sender, params: {
    queryId?: bigint;
    to: Address;
    amount: bigint;
    value?: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_WITHDRAW, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeAddress(params.to)
      .storeCoins(params.amount)
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.05'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async sendSetMintPaused(provider: ContractProvider, via: Sender, params: {
    queryId?: bigint;
    paused: boolean;
    value?: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_SET_MINT_PAUSED, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeBit(params.paused)
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.05'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async sendOwnerMint(provider: ContractProvider, via: Sender, params: {
    queryId?: bigint;
    itemIndex: bigint;
    itemOwner: Address;
    itemContent: Cell;
    value?: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_OWNER_MINT, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeUint(params.itemIndex, 64)
      .storeAddress(params.itemOwner)
      .storeRef(params.itemContent)
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.30'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async sendPublicMint(provider: ContractProvider, via: Sender, params: {
    queryId?: bigint;
    itemOwner: Address;
    itemContent: Cell;
    value?: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_PUBLIC_MINT, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeAddress(params.itemOwner)
      .storeRef(params.itemContent)
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.38'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async getCollectionData(provider: ContractProvider) {
    const res = await provider.get('get_collection_data', []);
    return {
      nextItemIndex: res.stack.readBigNumber(),
      collectionContent: res.stack.readCell(),
      owner: res.stack.readAddress(),
    };
  }

  async getRoyaltyParams(provider: ContractProvider) {
    const res = await provider.get('royalty_params', []);
    return {
      royaltyFactor: Number(res.stack.readBigNumber()),
      royaltyBase: Number(res.stack.readBigNumber()),
      royaltyAddress: res.stack.readAddress(),
    };
  }

  async getPublicMintTerms(provider: ContractProvider) {
    const res = await provider.get('get_public_mint_terms', []);
    return {
      treasuryContribution: res.stack.readBigNumber(),
      minAttach: res.stack.readBigNumber(),
      mintPaused: Number(res.stack.readBigNumber()),
      treasury: res.stack.readAddress(),
    };
  }
}
