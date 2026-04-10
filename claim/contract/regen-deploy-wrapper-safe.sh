#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/claim/contract"
mkdir -p "$ROOT/wrappers" "$ROOT/scripts"

############################################
# wrappers/ClaimDistributor.ts
############################################
cat > "$ROOT/wrappers/ClaimDistributor.ts" <<'TS'
import {
  Address,
  beginCell,
  Cell,
  contractAddress,
  Contract,
  ContractProvider,
  Sender,
  SendMode,
} from "ton-core";

export type ClaimDistributorConfig = {
  admin: Address;
  enabled: boolean;
  lastHash: bigint;
  emaWallet: Address;
  emxWallet: Address;
  emsWallet: Address;
  wemsWallet: Address;
  usdtWallet: Address;
};

export function claimDistributorConfigToCell(cfg: ClaimDistributorConfig): Cell {
  const wallets = beginCell()
    .storeAddress(cfg.emaWallet)
    .storeAddress(cfg.emxWallet)
    .storeAddress(cfg.emsWallet)
    .storeAddress(cfg.wemsWallet)
    .storeAddress(cfg.usdtWallet)
    .endCell();

  return beginCell()
    .storeAddress(cfg.admin)
    .storeUint(cfg.enabled ? 1 : 0, 1)
    .storeUint(cfg.lastHash, 256)
    .storeRef(wallets)
    .endCell();
}

export class ClaimDistributor implements Contract {
  constructor(
    readonly address: Address,
    readonly init?: { code: Cell; data: Cell }
  ) {}

  static createFromConfig(
    cfg: ClaimDistributorConfig,
    code: Cell,
    workchain = 0
  ) {
    const data = claimDistributorConfigToCell(cfg);
    const init = { code, data };
    return new ClaimDistributor(contractAddress(workchain, init), init);
  }

  async sendDeploy(
    provider: ContractProvider,
    via: Sender,
    value: bigint
  ) {
    await provider.internal(via, {
      value,
      sendMode: SendMode.PAY_GAS_SEPARATELY,
      body: beginCell().endCell(),
    });
  }
}
TS

############################################
# scripts/deployClaimDistributor.ts
############################################
cat > "$ROOT/scripts/deployClaimDistributor.ts" <<'TS'
import { Address, Cell, toNano } from "ton-core";
import { readFileSync } from "fs";
import { NetworkProvider } from "@ton/blueprint";
import { ClaimDistributor } from "../wrappers/ClaimDistributor";

function needEnv(name: string): string {
  const v = process.env[name]?.trim();
  if (!v) {
    throw new Error(`Missing env: ${name}`);
  }
  return v;
}

function parseAddr(name: string): Address {
  return Address.parse(needEnv(name));
}

export async function run(provider: NetworkProvider): Promise<void> {
  const bocPath = "/var/www/html/public/rwa/claim/contract/build-native/ClaimDistributor.boc";
  const code = Cell.fromBoc(readFileSync(bocPath))[0];

  const cfg = {
    admin: parseAddr("TON_TREASURY_ADDRESS"),
    enabled: true,
    lastHash: 0n,
    emaWallet: parseAddr("EMA_WALLET"),
    emxWallet: parseAddr("EMX_WALLET"),
    emsWallet: parseAddr("EMS_WALLET"),
    wemsWallet: parseAddr("WEMS_WALLET"),
    usdtWallet: parseAddr("USDT_WALLET"),
  };

  const contract = provider.open(ClaimDistributor.createFromConfig(cfg, code));

  console.log("==================================================");
  console.log("ClaimDistributor Deploy Preview");
  console.log("==================================================");
  console.log("Admin:       ", cfg.admin.toString());
  console.log("EMA_WALLET:  ", cfg.emaWallet.toString());
  console.log("EMX_WALLET:  ", cfg.emxWallet.toString());
  console.log("EMS_WALLET:  ", cfg.emsWallet.toString());
  console.log("WEMS_WALLET: ", cfg.wemsWallet.toString());
  console.log("USDT_WALLET: ", cfg.usdtWallet.toString());
  console.log("Address:     ", contract.address.toString());
  console.log("BOC:         ", bocPath);
  console.log("==================================================");

  await contract.sendDeploy(provider.sender(), toNano("0.20"));

  console.log("DEPLOYED:", contract.address.toString());
}
TS

echo "Wrapper + deploy script regenerated:"
echo " - $ROOT/wrappers/ClaimDistributor.ts"
echo " - $ROOT/scripts/deployClaimDistributor.ts"
