#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/ton-v10"
COLL_FC="$ROOT/contracts/v10_nft_collection.fc"
ITEM_FC="$ROOT/contracts/v10_nft_item.fc"
COLLECTION_JSON_LOCAL="/var/www/html/public/rwa/metadata/collection/rwa-collection-v10.json"
COLLECTION_JSON_URL="https://adoptgold.app/rwa/metadata/collection/rwa-collection-v10.json"
COLLECTION_IMG_URL="https://adoptgold.app/rwa/metadata/collection/rwa-collection.png"

STATUS_OK=1

pass() {
  echo "[PASS] $1"
}

warn() {
  echo "[WARN] $1"
}

fail() {
  echo "[FAIL] $1"
  STATUS_OK=0
}

echo "=================================================="
echo "V10 NFT COLLECTION READY PREFLIGHT"
echo "=================================================="

echo "[1] File existence"
[ -f "$COLL_FC" ] && pass "Collection FunC exists: $COLL_FC" || fail "Missing collection FunC: $COLL_FC"
[ -f "$ITEM_FC" ] && pass "Item FunC exists: $ITEM_FC" || fail "Missing item FunC: $ITEM_FC"
[ -f "$ROOT/contracts/imports/stdlib.fc" ] && pass "stdlib.fc exists" || fail "Missing stdlib.fc"
[ -f "$COLLECTION_JSON_LOCAL" ] && pass "Local collection JSON exists" || fail "Missing local collection JSON"

echo
echo "[2] Required collection getters"
grep -q "get_collection_data() method_id" "$COLL_FC" && pass "get_collection_data() found" || fail "get_collection_data() missing"
grep -q "get_nft_address_by_index(int index) method_id" "$COLL_FC" && pass "get_nft_address_by_index() found" || fail "get_nft_address_by_index() missing"
grep -q "get_nft_content(int index, cell individual_nft_content) method_id" "$COLL_FC" && pass "get_nft_content() found" || fail "get_nft_content() missing"
grep -q "royalty_params() method_id" "$COLL_FC" && pass "royalty_params() found" || warn "royalty_params() missing"

echo
echo "[3] Required item getter / transfer"
grep -q "get_nft_data() method_id" "$ITEM_FC" && pass "get_nft_data() found" || fail "get_nft_data() missing"
grep -q "op_transfer()" "$ITEM_FC" && pass "transfer opcode found" || fail "transfer opcode missing"
grep -q "transfer_ownership(" "$ITEM_FC" && pass "transfer_ownership() found" || fail "transfer_ownership() missing"

echo
echo "[4] Known bad helper scan"
if grep -nE "builder_null\?|equal_slices|workchain\(" "$COLL_FC" "$ITEM_FC" >/tmp/v10_bad_helpers.txt 2>/dev/null; then
  fail "Found forbidden undefined helper usage"
  cat /tmp/v10_bad_helpers.txt
else
  pass "No forbidden undefined helpers found"
fi
rm -f /tmp/v10_bad_helpers.txt

echo
echo "[5] Collection JSON syntax"
php -r '
$f = "'"$COLLECTION_JSON_LOCAL"'";
$j = file_get_contents($f);
if ($j === false) { fwrite(STDERR, "JSON_READ_FAIL\n"); exit(2); }
json_decode($j, true);
if (json_last_error() !== JSON_ERROR_NONE) {
  fwrite(STDERR, "JSON_BAD: " . json_last_error_msg() . PHP_EOL);
  exit(3);
}
echo "JSON_OK\n";
' >/tmp/v10_json_check.txt 2>&1 && pass "$(cat /tmp/v10_json_check.txt)" || { fail "$(cat /tmp/v10_json_check.txt)"; }
rm -f /tmp/v10_json_check.txt

echo
echo "[6] Required collection JSON fields"
php -r '
$f = "'"$COLLECTION_JSON_LOCAL"'";
$d = json_decode(file_get_contents($f), true);
$req = ["name","description","image","external_url","seller_fee_basis_points","fee_recipient"];
$missing = [];
foreach ($req as $k) {
  if (!array_key_exists($k, $d) || $d[$k] === "" || $d[$k] === null) $missing[] = $k;
}
if (!empty($missing)) {
  fwrite(STDERR, "MISSING_FIELDS: " . implode(",", $missing) . PHP_EOL);
  exit(4);
}
echo "FIELDS_OK\n";
' >/tmp/v10_json_fields.txt 2>&1 && pass "$(cat /tmp/v10_json_fields.txt)" || { fail "$(cat /tmp/v10_json_fields.txt)"; }
rm -f /tmp/v10_json_fields.txt

echo
echo "[7] Live collection JSON / image reachability"
curl -fsSI "$COLLECTION_JSON_URL" >/tmp/v10_json_head.txt 2>&1 && pass "Live collection JSON reachable: $COLLECTION_JSON_URL" || fail "Live collection JSON not reachable: $COLLECTION_JSON_URL"
curl -fsSI "$COLLECTION_IMG_URL" >/tmp/v10_img_head.txt 2>&1 && pass "Live collection image reachable: $COLLECTION_IMG_URL" || fail "Live collection image not reachable: $COLLECTION_IMG_URL"
rm -f /tmp/v10_json_head.txt /tmp/v10_img_head.txt

echo
echo "[8] JSON image / external_url consistency"
php -r '
$f = "'"$COLLECTION_JSON_LOCAL"'";
$d = json_decode(file_get_contents($f), true);
$ok = 1;
if (($d["image"] ?? "") !== "'"$COLLECTION_IMG_URL"'") {
  fwrite(STDERR, "IMAGE_URL_MISMATCH\n");
  $ok = 0;
}
if (!str_starts_with((string)($d["external_url"] ?? ""), "https://adoptgold.app/")) {
  fwrite(STDERR, "EXTERNAL_URL_NOT_ADOPTGOLD\n");
  $ok = 0;
}
if (!$ok) exit(5);
echo "URLS_OK\n";
' >/tmp/v10_json_urls.txt 2>&1 && pass "$(cat /tmp/v10_json_urls.txt)" || { fail "$(cat /tmp/v10_json_urls.txt)"; }
rm -f /tmp/v10_json_urls.txt

echo
echo "[9] Blueprint target visibility"
cd "$ROOT"
if npx blueprint build V10NftItem >/tmp/v10_item_build.log 2>&1; then
  pass "V10NftItem build passed"
else
  fail "V10NftItem build failed"
  sed -n '1,160p' /tmp/v10_item_build.log
fi

if npx blueprint build V10NftCollection >/tmp/v10_collection_build.log 2>&1; then
  pass "V10NftCollection build passed"
else
  fail "V10NftCollection build failed"
  sed -n '1,200p' /tmp/v10_collection_build.log
fi
rm -f /tmp/v10_item_build.log /tmp/v10_collection_build.log

echo
echo "[10] Final status"
if [ "$STATUS_OK" -eq 1 ]; then
  echo "READY_STATUS=PASS"
  echo "Collection contract getters present, item getter present, JSON valid, live URLs reachable, builds passed."
  exit 0
else
  echo "READY_STATUS=FAIL"
  echo "Fix FAIL items above before deploy."
  exit 1
fi
