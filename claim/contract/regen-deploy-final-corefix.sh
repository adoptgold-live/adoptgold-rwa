#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/claim/contract"
mkdir -p "$ROOT/wrappers" "$ROOT/scripts" "$ROOT/build-native"

############################################
# build-native.sh
############################################
cat > "$ROOT/build-native.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/claim/contract"
BUILD="$ROOT/build-native"
CONTRACT="$ROOT/contracts/ClaimDistributor.fc"
STDLIB="/opt/ton-stdlib/stdlib.fc"

mkdir -p "$BUILD"

test -f "$CONTRACT" || { echo "Missing contract: $CONTRACT"; exit 1; }
test -f "$STDLIB"   || { echo "Missing stdlib: $STDLIB"; exit 1; }

rm -f "$BUILD/ClaimDistributor.fif" "$BUILD/ClaimDistributor.boc"
rm -f "$ROOT/contracts/ClaimDistributor.boc" "$ROOT/ClaimDistributor.boc"

echo "Using contract: $CONTRACT"
echo "Using stdlib:   $STDLIB"

/usr/local/bin/func -SPA -I /opt/ton-stdlib "$CONTRACT" -o "$BUILD/ClaimDistributor.fif"

test -s "$BUILD/ClaimDistributor.fif" || { echo "FIF not created"; exit 1; }

/usr/local/bin/fift -I /opt/ton-stdlib -s "$BUILD/ClaimDistributor.fif"

if [ -f "$ROOT/contracts/ClaimDistributor.boc" ]; then
  cp -f "$ROOT/contracts/ClaimDistributor.boc" "$BUILD/ClaimDistributor.boc"
fi

if [ -f "$ROOT/ClaimDistributor.boc" ]; then
  cp -f "$ROOT/ClaimDistributor.boc" "$BUILD/ClaimDistributor.boc"
fi

test -s "$BUILD/ClaimDistributor.boc" || { echo "BOC not created"; exit 1; }

echo "OK:"
ls -lh "$BUILD/ClaimDistributor.fif" "$BUILD/ClaimDistributor.boc"
sha256sum "$BUILD/ClaimDistributor.boc"
SH
chmod +x "$ROOT/build-native.sh"

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
} from "@ton/core";

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

  static createFromConfig(cfg: ClaimDistributorConfig, code: Cell, workchain = 0) {
    const data = claimDistributorConfigToCell(cfg);
    const init = { code, data };
    return new ClaimDistributor(contractAddress(workchain, init), init);
  }

  async sendDeploy(provider: ContractProvider, via: Sender, value: bigint) {
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
import { Address, Cell, toNano } from "@ton/core";
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

echo "Regen complete:"
echo " - $ROOT/build-native.sh"
echo " - $ROOT/wrappers/ClaimDistributor.ts"
echo " - $ROOT/scripts/deployClaimDistributor.ts"
