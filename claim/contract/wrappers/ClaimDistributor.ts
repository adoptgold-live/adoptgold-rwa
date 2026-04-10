import { Address, Cell, Contract, contractAddress } from '@ton/core';

export type ClaimDistributorConfig = {
  admin: Address;
};

export function configToCell(config: ClaimDistributorConfig): Cell {
  return new Cell();
}

export class ClaimDistributor implements Contract {
  readonly address: Address;

  constructor(address: Address) {
    this.address = address;
  }

  static createFromConfig(config: ClaimDistributorConfig, code: Cell) {
    const data = configToCell(config);
    const init = { code, data };
    return new ClaimDistributor(contractAddress(0, init));
  }
}
