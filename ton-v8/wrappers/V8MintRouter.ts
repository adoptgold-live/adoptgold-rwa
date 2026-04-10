/**
 * /wrappers/V8MintRouter.ts
 * Version: v8.0.0-clean
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

export const OP_PUBLIC_MINT = 0x504d4e54; // PMNT
export const OP_WITHDRAW = 0x57445448;    // WDTH
export const OP_SET_PAUSED = 0x50415553;  // PAUS

export type V8MintRouterConfig = {
  owner: Address;
  collection: Address;
  treasury: Address;
  mintPaused: boolean;
  treasuryContribution: bigint;
  publicMinAttach: bigint;
  collectionForwardValue: bigint;
};

export function v8MintRouterConfigToCell(config: V8MintRouterConfig): Cell {
  return beginCell()
    .storeAddress(config.owner)
    .storeAddress(config.collection)
    .storeAddress(config.treasury)
    .storeBit(config.mintPaused)
    .storeCoins(config.treasuryContribution)
    .storeCoins(config.publicMinAttach)
    .storeCoins(config.collectionForwardValue)
    .endCell();
}

export class V8MintRouter implements Contract {
  constructor(
    readonly address: Address,
    readonly init?: { code: Cell; data: Cell },
  ) {}

  static createFromAddress(address: Address) {
    return new V8MintRouter(address);
  }

  static createFromConfig(config: V8MintRouterConfig, code: Cell, workchain = 0) {
    const data = v8MintRouterConfigToCell(config);
    const init = { code, data };
    return new V8MintRouter(contractAddress(workchain, init), init);
  }

  async sendDeploy(provider: ContractProvider, via: Sender, value: bigint) {
    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body: beginCell().endCell(),
    });
  }

  async sendPublicMint(provider: ContractProvider, via: Sender, params: {
    queryId?: bigint;
    owner: Address;
    content: Cell;
    value: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_PUBLIC_MINT, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeAddress(params.owner)
      .storeRef(params.content)
      .endCell();

    await provider.internal(via, {
      value: params.value,
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

  async sendSetPaused(provider: ContractProvider, via: Sender, params: {
    paused: boolean;
    queryId?: bigint;
    value?: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_SET_PAUSED, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeBit(params.paused)
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.05'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async getRouterConfig(provider: ContractProvider) {
    const res = await provider.get('get_router_config', []);
    return {
      collection: res.stack.readAddress(),
      treasury: res.stack.readAddress(),
      paused: res.stack.readBigNumber(),
      treasuryContribution: res.stack.readBigNumber(),
      publicMinAttach: res.stack.readBigNumber(),
      collectionForwardValue: res.stack.readBigNumber(),
    };
  }
}
