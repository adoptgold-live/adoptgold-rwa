import { Address, beginCell, Cell, Contract, contractAddress, ContractProvider, Sender, SendMode } from '@ton/core';

export type V9NftCollectionConfig = {
  owner: Address;
  nextIndex: bigint;
  content: Cell;
  itemCode: Cell;
  royaltyFactor: number;
  royaltyBase: number;
  royaltyAddress: Address;
  treasury: Address;
  paused: number;
  minStorageReserve: bigint;
  itemDeployValue: bigint;
  publicMinAttach: bigint;
  primaryTreasuryShare: bigint;
};

function buildCfgRef(config: V9NftCollectionConfig): Cell {
  return beginCell()
    .storeUint(config.royaltyFactor, 16)
    .storeUint(config.royaltyBase, 16)
    .storeAddress(config.royaltyAddress)
    .storeAddress(config.treasury)
    .storeUint(config.paused, 1)
    .storeCoins(config.minStorageReserve)
    .storeCoins(config.itemDeployValue)
    .storeCoins(config.publicMinAttach)
    .storeCoins(config.primaryTreasuryShare)
    .endCell();
}

export function v9NftCollectionConfigToCell(config: V9NftCollectionConfig): Cell {
  const cfgRef = buildCfgRef(config);

  return beginCell()
    .storeAddress(config.owner)
    .storeUint(config.nextIndex, 64)
    .storeRef(config.content)
    .storeRef(config.itemCode)
    .storeRef(cfgRef)
    .endCell();
}

export class V9NftCollection implements Contract {
  constructor(
    readonly address: Address,
    readonly init?: { code: Cell; data: Cell },
  ) {}

  static createFromAddress(address: Address) {
    return new V9NftCollection(address);
  }

  static createFromConfig(config: V9NftCollectionConfig, code: Cell, workchain = 0) {
    const data = v9NftCollectionConfigToCell(config);
    const init = { code, data };
    return new V9NftCollection(contractAddress(workchain, init), init);
  }

  async sendDeploy(provider: ContractProvider, via: Sender, value: bigint) {
    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body: beginCell().endCell(),
    });
  }

  async sendPublicMint(
    provider: ContractProvider,
    via: Sender,
    opts: { value: bigint; itemMetadataUrl: string; queryId?: bigint }
  ) {
    const itemContent = beginCell()
      .storeStringTail(opts.itemMetadataUrl)
      .endCell();

    const body = beginCell()
      .storeUint(0x504d494e, 32)
      .storeUint(opts.queryId ?? 0n, 64)
      .storeRef(itemContent)
      .endCell();

    await provider.internal(via, {
      value: opts.value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async getCollectionData(provider: ContractProvider) {
    const res = await provider.get('get_collection_data', []);
    return {
      nextIndex: res.stack.readBigNumber(),
      content: res.stack.readCell(),
      owner: res.stack.readAddress(),
    };
  }

  async getRoyaltyParams(provider: ContractProvider) {
    const res = await provider.get('royalty_params', []);
    return {
      factor: Number(res.stack.readBigNumber()),
      base: Number(res.stack.readBigNumber()),
      address: res.stack.readAddress(),
    };
  }

  async getPublicMintState(provider: ContractProvider) {
    const res = await provider.get('get_public_mint_state', []);
    return {
      owner: res.stack.readAddress(),
      treasury: res.stack.readAddress(),
      paused: Number(res.stack.readBigNumber()),
      publicMinAttach: res.stack.readBigNumber(),
      itemDeployValue: res.stack.readBigNumber(),
      primaryTreasuryShare: res.stack.readBigNumber(),
    };
  }
}
