#!/usr/bin/env bash
# /var/www/html/public/rwa/ton/scripts/testPublicMint.sh
# Version: v3.0.0-20260330-public-mint-033-ton

set -e

COLLECTION=$(grep RWA_CERT_COLLECTION_ADDRESS /var/www/secure/.env | tail -1 | cut -d '=' -f2)

if [ -z "$COLLECTION" ]; then
  echo "Collection address not set in env."
  exit 1
fi

read -p "Enter mnemonic: " MNEMONIC
read -p "Enter owner wallet: " OWNER

/usr/bin/tsx /var/www/html/public/rwa/ton/scripts/mintItem.v3.ts \
  --collection "$COLLECTION" \
  --owner "$OWNER" \
  --suffix "TEST-V3.json" \
  --tx_value 0.38 \
  --mnemonic "$MNEMONIC"
