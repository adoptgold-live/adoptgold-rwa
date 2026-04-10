#!/usr/bin/env bash
# /var/www/html/public/rwa/ton/scripts/compileV3.sh
# Version: v3.0.0-20260330-public-mint-033-ton

set -e

CONTRACT="/var/www/html/public/rwa/ton/contracts/rwa_cert_collection_v3.fc"
OUT="/var/www/html/public/rwa/ton/build/rwa_cert_collection_v3.boc"

mkdir -p /var/www/html/public/rwa/ton/build

echo "Compiling V3 contract..."
/usr/local/bin/func -o /var/www/html/public/rwa/ton/build/collection_v3.fif $CONTRACT
/usr/local/bin/fift -s /usr/share/ton/fift/stdlib/Asm.fif /var/www/html/public/rwa/ton/build/collection_v3.fif > $OUT

echo "Done."
echo "BOC output:"
echo $OUT
