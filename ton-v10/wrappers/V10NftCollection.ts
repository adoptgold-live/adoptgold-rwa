import {
  Address,
  beginCell,
  Cell,
  contractAddress,
  Contract,
  ContractProvider,
  Sender,
  SendMode,
  toNano
} from '@ton/core';

export type V10NftCollectionConfig = {
  owner: Address;
  nextIndex: bigint;
  collectionContent: Cell;
  itemCode: Cell;
  cfgRef: Cell;
};

export type V10CollectionData = {
  nextItemIndex: bigint;
  collectionContent: Cell;
  ownerAddress: Address;
};

export type V10RoyaltyData = {
  factor: bigint;
  base: bigint;
  address: Address;
};

export function v10NftCollectionConfigToCell(config: V10NftCollectionConfig): Cell {
  return beginCell()
    .storeAddress(config.owner)
    .storeUint(config.nextIndex, 64)
    .storeRef(config.collectionContent)
    .storeRef(config.itemCode)
    .storeRef(config.cfgRef)
    .endCell();
}

export function offchainSnakeCell(uri: string): Cell {
  return beginCell().storeUint(0x01, 8).storeBuffer(Buffer.from(uri, 'utf8')).endCell();
}

export function plainStringCell(value: string): Cell {
  return beginCell().storeBuffer(Buffer.from(value, 'utf8')).endCell();
}

export class V10NftCollection implements Contract {
  constructor(readonly address: Address, readonly init?: { code: Cell; data: Cell }) {}

  static createFromAddress(address: Address) {
    return new V10NftCollection(address);
  }

  static createFromConfig(config: V10NftCollectionConfig, code: Cell, workchain = 0) {
    const data = v10NftCollectionConfigToCell(config);
    const init = { code, data };
    return new V10NftCollection(contractAddress(workchain, init), init);
  }

  async sendDeploy(provider: ContractProvider, via: Sender, value: bigint) {
    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body: beginCell().endCell()
    });
  }

  async sendPublicMint(
    provider: ContractProvider,
    via: Sender,
    params: {
      value?: bigint;
      queryId?: bigint;
      itemSuffix: string;
    }
  ) {
    const body = beginCell()
      .storeUint(0x504d494e, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeRef(plainStringCell(params.itemSuffix))
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.48'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body
    });
  }

  async sendOwnerMint(
    provider: ContractProvider,
    via: Sender,
    params: {
      value?: bigint;
      queryId?: bigint;
      mintTo: Address;
      itemSuffix: string;
    }
  ) {
    const body = beginCell()
      .storeUint(1, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeAddress(params.mintTo)
      .storeRef(plainStringCell(params.itemSuffix))
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.10'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body
    });
  }

  async sendWithdraw(
    provider: ContractProvider,
    via: Sender,
    params: {
      value?: bigint;
      queryId?: bigint;
      amount: bigint;
    }
  ) {
    const body = beginCell()
      .storeUint(0x57544844, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeCoins(params.amount)
      .endCell();

    await provider.internal(via, {
      value: params.value ?? toNano('0.05'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body
    });
  }

  async sendSweep(
    provider: ContractProvider,
    via: Sender,
    params?: {
      value?: bigint;
      queryId?: bigint;
    }
  ) {
    const body = beginCell()
      .storeUint(0x53574550, 32)
      .storeUint(params?.queryId ?? 0n, 64)
      .endCell();

    await provider.internal(via, {
      value: params?.value ?? toNano('0.05'),
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body
    });
  }

  async getCollectionData(provider: ContractProvider): Promise<V10CollectionData> {
    const res = await provider.get('get_collection_data', []);
    return {
      nextItemIndex: res.stack.readBigNumber(),
      collectionContent: res.stack.readCell(),
      ownerAddress: res.stack.readAddress()
    };
  }

  async getNftAddressByIndex(provider: ContractProvider, index: bigint): Promise<Address> {
    const res = await provider.get('get_nft_address_by_index', [{ type: 'int', value: index }]);
    return res.stack.readAddress();
  }

  async getNftContent(provider: ContractProvider, index: bigint, individualContent: Cell): Promise<Cell> {
    const res = await provider.get('get_nft_content', [
      { type: 'int', value: index },
      { type: 'cell', cell: individualContent }
    ]);
    return res.stack.readCell();
  }

  async getRoyaltyParams(provider: ContractProvider): Promise<V10RoyaltyData> {
    const res = await provider.get('royalty_params', []);
    return {
      factor: res.stack.readBigNumber(),
      base: res.stack.readBigNumber(),
      address: res.stack.readAddress()
    };
  }
}
