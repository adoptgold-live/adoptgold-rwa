/**
 * /var/www/html/public/rwa/ton/wrappers/V8NftCollection.ts
 * Version: v8.0.1-20260330-router-collection-wrapper-with-sweep
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

export const OP_SET_OWNER = 0x4f574e52;
export const OP_SET_ROUTER = 0x52545253;
export const OP_SET_CONTENT = 0x434f4e54;
export const OP_SET_MINT_PAUSED = 0x50415553;
export const OP_OWNER_MINT = 0x4d494e54;
export const OP_ROUTER_MINT = 0x564d494e;
export const OP_WITHDRAW = 0x57445448;

export type V8NftCollectionConfig = {
  owner: Address;
  router: Address;
  nextItemIndex: bigint;
  collectionContent: Cell;
  nftItemCode: Cell;
  royaltyFactor: number;
  royaltyBase: number;
  royaltyAddress: Address;
  mintPaused: boolean;
};

export function v8NftCollectionConfigToCell(config: V8NftCollectionConfig): Cell {
  return beginCell()
    .storeAddress(config.owner)
    .storeAddress(config.router)
    .storeUint(config.nextItemIndex, 64)
    .storeRef(config.collectionContent)
    .storeRef(config.nftItemCode)
    .storeUint(config.royaltyFactor, 16)
    .storeUint(config.royaltyBase, 16)
    .storeAddress(config.royaltyAddress)
    .storeBit(config.mintPaused)
    .endCell();
}

export class V8NftCollection implements Contract {
  constructor(
    readonly address: Address,
    readonly init?: { code: Cell; data: Cell },
  ) {}

  static createFromAddress(address: Address) {
    return new V8NftCollection(address);
  }

  static createFromConfig(config: V8NftCollectionConfig, code: Cell, workchain = 0) {
    const data = v8NftCollectionConfigToCell(config);
    const init = { code, data };
    return new V8NftCollection(contractAddress(workchain, init), init);
  }

  async sendDeploy(provider: ContractProvider, via: Sender, value: bigint) {
    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body: beginCell().endCell(),
    });
  }

  async sendSetRouter(provider: ContractProvider, via: Sender, params: {
    queryId?: bigint;
    router: Address;
    value?: bigint;
  }) {
    const body = beginCell()
      .storeUint(OP_SET_ROUTER, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeAddress(params.router)
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
      value: params.value ?? toNano('0.15'),
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

  async getNftAddressByIndex(provider: ContractProvider, index: bigint) {
    const res = await provider.get('get_nft_address_by_index', [{ type: 'int', value: index }]);
    return res.stack.readAddress();
  }

  async getRouterState(provider: ContractProvider) {
    const res = await provider.get('get_router_state', []);
    return {
      router: res.stack.readAddress(),
      mintPaused: Number(res.stack.readBigNumber()),
    };
  }
}
