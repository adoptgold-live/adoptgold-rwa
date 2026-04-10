import {
  Address,
  beginCell,
  Cell,
  Contract,
  contractAddress,
  ContractProvider,
  Sender,
  SendMode,
} from '@ton/core';

export type ClaimProofEMXConfig = {
  admin: Address;
  treasury: Address;
  enabled: boolean;
  signerPublicKey: bigint;
  minAttached: bigint;
  lastClaimHash: bigint;
};

export type ClaimProofEMXConstants = {
  treasuryFee: bigint;
  contractReserve: bigint;
  contractFloor: bigint;
};

export const OP_CLAIM_PROOF = 0x4350454d;
export const OP_SET_ENABLED = 0x53454e42;
export const OP_SWEEP_TON = 0x53575054;

export function claimProofEMXConfigToCell(config: ClaimProofEMXConfig): Cell {
  const coreRef = beginCell()
    .storeUint(config.signerPublicKey, 256)
    .storeCoins(config.minAttached)
    .storeUint(config.lastClaimHash, 256)
    .endCell();

  return beginCell()
    .storeAddress(config.admin)
    .storeAddress(config.treasury)
    .storeUint(config.enabled ? 1 : 0, 1)
    .storeRef(coreRef)
    .endCell();
}

export class ClaimProofEMX implements Contract {
  constructor(
    readonly address: Address,
    readonly init?: { code: Cell; data: Cell },
  ) {}

  static createFromAddress(address: Address) {
    return new ClaimProofEMX(address);
  }

  static createFromConfig(config: ClaimProofEMXConfig, code: Cell, workchain = 0) {
    const data = claimProofEMXConfigToCell(config);
    const init = { code, data };
    return new ClaimProofEMX(contractAddress(workchain, init), init);
  }

  async sendDeploy(provider: ContractProvider, via: Sender, value: bigint) {
    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body: beginCell().endCell(),
    });
  }

  async sendSetEnabled(
    provider: ContractProvider,
    via: Sender,
    value: bigint,
    enabled: boolean,
  ) {
    const body = beginCell()
      .storeUint(OP_SET_ENABLED, 32)
      .storeUint(0n, 64)
      .storeUint(enabled ? 1 : 0, 1)
      .endCell();

    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async sendSweepTon(
    provider: ContractProvider,
    via: Sender,
    value: bigint,
    amount: bigint,
  ) {
    const body = beginCell()
      .storeUint(OP_SWEEP_TON, 32)
      .storeUint(0n, 64)
      .storeCoins(amount)
      .endCell();

    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body,
    });
  }

  async getConfig(provider: ContractProvider) {
    const res = await provider.get('get_config', []);

    return {
      admin: res.stack.readAddress(),
      treasury: res.stack.readAddress(),
      enabled: res.stack.readBigNumber() !== 0n,
      signerPublicKey: res.stack.readBigNumber(),
      minAttached: res.stack.readBigNumber(),
      lastClaimHash: res.stack.readBigNumber(),
    };
  }

  async getConstants(provider: ContractProvider): Promise<ClaimProofEMXConstants> {
    const res = await provider.get('get_constants', []);

    return {
      treasuryFee: res.stack.readBigNumber(),
      contractReserve: res.stack.readBigNumber(),
      contractFloor: res.stack.readBigNumber(),
    };
  }

  async getLastClaimHash(provider: ContractProvider): Promise<bigint> {
    const res = await provider.get('get_last_claim_hash', []);
    return res.stack.readBigNumber();
  }
}
