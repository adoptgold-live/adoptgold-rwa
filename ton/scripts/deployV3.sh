#!/usr/bin/env bash
# /var/www/html/public/rwa/ton/scripts/deployV3.sh
# Version: v3.0.0-20260330-public-mint-033-ton

set -e

BOC="/var/www/html/public/rwa/ton/build/rwa_cert_collection_v3.boc"

if [ ! -f "$BOC" ]; then
  echo "BOC not found. Run compileV3.sh first."
  exit 1
fi

read -p "Enter mnemonic: " MNEMONIC

/usr/bin/tsx /var/www/html/public/rwa/ton/scripts/deployCollectionV3.ts \
  --code_boc "$BOC" \
  --mnemonic "$MNEMONIC" \
  --value 0.25
