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

export type ClaimEmxConfig = {
  admin: Address;
  treasury: Address;
  enabled: boolean;
  signerPublicKey: bigint;
  minAttached: bigint;
  lastClaimHash: bigint;
  emxMaster: Address;
  emxWallet: Address | null;
};

export type ClaimEmxConstants = {
  treasuryFee: bigint;
  contractReserve: bigint;
  contractFloor: bigint;
  minForwardToJw: bigint;
};

export const OP_CLAIM = 0x434c454d;
export const OP_SET_ENABLED = 0x53454e42;
export const OP_SET_EMX_WALLET = 0x5345574c;
export const OP_SWEEP_TON = 0x53575054;

export function claimEmxConfigToCell(config: ClaimEmxConfig): Cell {
  const walletRef = beginCell()
    .storeAddress(config.emxWallet)
    .endCell();

  const coreRef = beginCell()
    .storeUint(config.signerPublicKey, 256)
    .storeCoins(config.minAttached)
    .storeUint(config.lastClaimHash, 256)
    .storeAddress(config.emxMaster)
    .storeRef(walletRef)
    .endCell();

  return beginCell()
    .storeAddress(config.admin)
    .storeAddress(config.treasury)
    .storeUint(config.enabled ? 1 : 0, 1)
    .storeRef(coreRef)
    .endCell();
}

export class ClaimEmx implements Contract {
  constructor(
    readonly address: Address,
    readonly init?: { code: Cell; data: Cell },
  ) {}

  static createFromAddress(address: Address) {
    return new ClaimEmx(address);
  }

  static createFromConfig(config: ClaimEmxConfig, code: Cell, workchain = 0) {
    const data = claimEmxConfigToCell(config);
    const init = { code, data };
    return new ClaimEmx(contractAddress(workchain, init), init);
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

  async sendSetEmxWallet(
    provider: ContractProvider,
    via: Sender,
    value: bigint,
    wallet: Address,
  ) {
    const body = beginCell()
      .storeUint(OP_SET_EMX_WALLET, 32)
      .storeUint(0n, 64)
      .storeAddress(wallet)
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
      emxMaster: res.stack.readAddress(),
      emxWallet: res.stack.readAddressOpt(),
    };
  }

  async getConstants(provider: ContractProvider): Promise<ClaimEmxConstants> {
    const res = await provider.get('get_constants', []);

    return {
      treasuryFee: res.stack.readBigNumber(),
      contractReserve: res.stack.readBigNumber(),
      contractFloor: res.stack.readBigNumber(),
      minForwardToJw: res.stack.readBigNumber(),
    };
  }
}
