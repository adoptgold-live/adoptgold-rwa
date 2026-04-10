import { Address, Cell, Contract, contractAddress } from '@ton/core';

export type V9NftItemConfig = {
  index: bigint;
  collection: Address;
  owner: Address;
  content: Cell;
};

export class V9NftItem implements Contract {
  constructor(
    readonly address: Address,
    readonly init?: { code: Cell; data: Cell },
  ) {}

  static createFromAddress(address: Address) {
    return new V9NftItem(address);
  }

  static createFromConfig(config: V9NftItemConfig, code: Cell, workchain = 0) {
    config = config;
    const data = Cell.EMPTY;
    const init = { code, data };
    return new V9NftItem(contractAddress(workchain, init), init);
  }
}
