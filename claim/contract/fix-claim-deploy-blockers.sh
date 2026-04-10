#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/claim/contract"

############################################
# 1) Fix ESM import path
############################################
cat > "$ROOT/scripts/deployClaimDistributor.ts" <<'TS'
import { Address, Cell, toNano } from "@ton/core";
import { readFileSync } from "fs";
import { NetworkProvider } from "@ton/blueprint";
import { ClaimDistributor } from "../wrappers/ClaimDistributor.ts";

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

############################################
# 2) Stdlib diagnostics helper
############################################
cat > "$ROOT/check-stdlib.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

echo "Checking canonical stdlib path..."
if [ -f /opt/ton-stdlib/stdlib.fc ]; then
  echo "OK: /opt/ton-stdlib/stdlib.fc exists"
  ls -lh /opt/ton-stdlib/stdlib.fc
  exit 0
fi

echo "MISSING: /opt/ton-stdlib/stdlib.fc"
echo
echo "Searching likely locations..."
find /opt /usr/local /usr -type f -name stdlib.fc 2>/dev/null | head -n 20 || true
SH
chmod +x "$ROOT/check-stdlib.sh"

echo "Patched:"
echo " - $ROOT/scripts/deployClaimDistributor.ts"
echo " - $ROOT/check-stdlib.sh"
