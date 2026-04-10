# V10 Workspace

Same-config rebuild of V9 with corrected workspace hygiene.

## Locked config
- same owner
- same treasury
- same royalty
- same collection JSON URL family
- same item metadata base URL
- same mint economics

## Before deploy
1. put stdlib.fc into contracts/imports/
2. npm install
3. bash scripts/check-v10-imports.sh
4. bash scripts/check-v10-metadata.sh
5. bash scripts/preflight-check.sh
6. blueprint build targets must be visible
7. only then deploy testnet/mainnet
