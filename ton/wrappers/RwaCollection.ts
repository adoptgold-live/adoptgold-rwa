import { Address, beginCell, Cell, contractAddress, Contract, ContractProvider, Sender, SendMode } from '@ton/core';

export type RwaCollectionConfig = {
  ownerAddress: Address;
  treasuryAddress: Address;
  nextItemIndex: bigint;
  collectionContentUri: string;
  commonContentPrefix: string;
  nftItemCode: Cell;
  royaltyFactor: number;
  royaltyBase: number;
};

function makeOffchainUriCell(uri: string): Cell {
  return beginCell()
    .storeUint(0x01, 8)
    .storeBuffer(Buffer.from(uri, 'utf8'))
    .endCell();
}

export function rwaCollectionConfigToCell(config: RwaCollectionConfig): Cell {
  const content = beginCell()
    .storeRef(makeOffchainUriCell(config.collectionContentUri))
    .storeRef(beginCell().storeBuffer(Buffer.from(config.commonContentPrefix, 'utf8')).endCell())
    .endCell();

  const royalty = beginCell()
    .storeUint(config.royaltyFactor, 16)
    .storeUint(config.royaltyBase, 16)
    .storeAddress(config.treasuryAddress)
    .endCell();

  return beginCell()
    .storeAddress(config.ownerAddress)
    .storeAddress(config.treasuryAddress)
    .storeUint(config.nextItemIndex, 64)
    .storeRef(content)
    .storeRef(config.nftItemCode)
    .storeRef(royalty)
    .endCell();
}

export class RwaCollection implements Contract {
  constructor(readonly address: Address, readonly init?: { code: Cell; data: Cell }) {}

  static createFromConfig(config: RwaCollectionConfig, code: Cell, workchain = 0) {
    const data = rwaCollectionConfigToCell(config);
    const init = { code, data };
    return new RwaCollection(contractAddress(workchain, init), init);
  }

  static createFromAddress(address: Address) {
    return new RwaCollection(address);
  }

  async sendDeploy(provider: ContractProvider, via: Sender, value: bigint) {
    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body: beginCell().endCell(),
    });
  }

  async sendMint(
    provider: ContractProvider,
    via: Sender,
    params: {
      value: bigint;
      queryId?: bigint;
      itemIndex: bigint;
      deployAmount: bigint;
      recipient: Address;
      itemContentSuffix: string;
    },
  ) {
    const body = beginCell()
      .storeUint(1, 32)
      .storeUint(params.queryId ?? 0n, 64)
      .storeUint(params.itemIndex, 64)
      .storeCoins(params.deployAmount)
      .storeAddress(params.recipient)
      .storeRef(beginCell().storeBuffer(Buffer.from(params.itemContentSuffix, 'utf8')).endCell())
      .endCell();

    await provider.internal(via, {
      value: params.value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async getCollectionData(provider: ContractProvider) {
    const res = await provider.get('get_collection_data', []);
    return {
      nextItemIndex: res.stack.readBigNumber(),
      collectionContent: res.stack.readCell(),
      ownerAddress: res.stack.readAddress(),
    };
  }

  async getRoyaltyParams(provider: ContractProvider) {
    const res = await provider.get('royalty_params', []);
    return {
      factor: Number(res.stack.readBigNumber()),
      base: Number(res.stack.readBigNumber()),
      destination: res.stack.readAddress(),
    };
  }

  async getNftAddressByIndex(provider: ContractProvider, index: bigint) {
    const res = await provider.get('get_nft_address_by_index', [
      { type: 'int', value: index },
    ]);
    return res.stack.readAddress();
  }
}
