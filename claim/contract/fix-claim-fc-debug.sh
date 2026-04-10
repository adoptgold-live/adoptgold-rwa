#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/claim/contract"
mkdir -p "$ROOT/contracts" "$ROOT/build-native"

############################################
# contracts/ClaimDistributor.fc
############################################
cat > "$ROOT/contracts/ClaimDistributor.fc" <<'FC'
#include "stdlib.fc";

const int OP_EXECUTE = 0x434C4D32;

const int TOKEN_EMA  = 1;
const int TOKEN_EMX  = 2;
const int TOKEN_EMS  = 3;
const int TOKEN_WEMS = 4;
const int TOKEN_USDT = 5;

(slice, int, int, cell) load_data() inline {
    slice ds = get_data().begin_parse();
    slice admin = ds~load_msg_addr();
    int enabled = ds~load_uint(1);
    int last_hash = ds~load_uint(256);
    cell wallets = ds~load_ref();
    return (admin, enabled, last_hash, wallets);
}

() save_data(slice admin, int enabled, int last_hash, cell wallets) impure inline {
    set_data(
        begin_cell()
            .store_slice(admin)
            .store_uint(enabled, 1)
            .store_uint(last_hash, 256)
            .store_ref(wallets)
        .end_cell()
    );
}

int same_addr(slice a, slice b) inline {
    return slice_hash(a) == slice_hash(b);
}

slice get_wallet(cell wallets, int token_code) inline {
    slice ds = wallets.begin_parse();
    slice w1 = ds~load_msg_addr();
    slice w2 = ds~load_msg_addr();
    slice w3 = ds~load_msg_addr();
    slice w4 = ds~load_msg_addr();
    slice w5 = ds~load_msg_addr();

    if (token_code == TOKEN_EMA)  return w1;
    if (token_code == TOKEN_EMX)  return w2;
    if (token_code == TOKEN_EMS)  return w3;
    if (token_code == TOKEN_WEMS) return w4;
    if (token_code == TOKEN_USDT) return w5;

    throw(401);
    return w1;
}

int calc_hash(int qid, int token_code, slice to_addr, int amount, int ref_hash) inline {
    return cell_hash(
        begin_cell()
            .store_uint(qid, 64)
            .store_uint(token_code, 8)
            .store_slice(to_addr)
            .store_coins(amount)
            .store_uint(ref_hash, 256)
        .end_cell()
    );
}

cell build_jetton_body(int amount, slice to_addr, int ref_hash) inline {
    cell fwd = begin_cell()
        .store_uint(ref_hash, 256)
    .end_cell();

    return begin_cell()
        .store_uint(0x0f8a7ea5, 32)
        .store_uint(0, 64)
        .store_coins(amount)
        .store_slice(to_addr)
        .store_slice(to_addr)
        .store_uint(0, 1)
        .store_coins(1)
        .store_uint(1, 1)
        .store_ref(fwd)
    .end_cell();
}

() send_jetton(slice wallet_addr, int amount, slice to_addr, int ref_hash) impure inline {
    cell body = build_jetton_body(amount, to_addr, ref_hash);

    cell msg = begin_cell()
        .store_uint(0x18, 6)
        .store_slice(wallet_addr)
        .store_coins(5000000)
        .store_uint(0, 1 + 4 + 4 + 64 + 32 + 1 + 1)
        .store_ref(body)
    .end_cell();

    send_raw_message(msg, 1);
}

() recv_internal(int my_balance, int msg_value, cell in_msg_full, slice in_msg_body) impure {
    if (in_msg_body.slice_empty?()) {
        return ();
    }

    slice cs = in_msg_full.begin_parse();
    int flags = cs~load_uint(4);
    if (flags & 1) {
        return ();
    }

    slice sender = cs~load_msg_addr();

    var (admin, enabled, last_hash, wallets) = load_data();

    throw_unless(403, same_addr(sender, admin));
    throw_unless(404, enabled == 1);

    int op = in_msg_body~load_uint(32);
    int qid = in_msg_body~load_uint(64);

    if (op != OP_EXECUTE) {
        throw(400);
    }

    int token_code = in_msg_body~load_uint(8);
    slice to_addr = in_msg_body~load_msg_addr();
    int amount = in_msg_body~load_coins();
    int ref_hash = in_msg_body~load_uint(256);

    throw_unless(406, amount > 0);

    int h = calc_hash(qid, token_code, to_addr, amount, ref_hash);
    throw_unless(405, h != last_hash);

    slice wallet_addr = get_wallet(wallets, token_code);

    send_jetton(wallet_addr, amount, to_addr, ref_hash);

    save_data(admin, enabled, h, wallets);
}
FC

############################################
# build-native.sh with debug
############################################
cat > "$ROOT/build-native.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/html/public/rwa/claim/contract"
BUILD="$ROOT/build-native"
CONTRACT="$ROOT/contracts/ClaimDistributor.fc"
STDLIB_DIR="$ROOT/contracts"
STDLIB_FILE="$ROOT/contracts/stdlib.fc"

mkdir -p "$BUILD"

test -f "$CONTRACT" || { echo "Missing contract: $CONTRACT"; exit 1; }
test -f "$STDLIB_FILE" || { echo "Missing local stdlib: $STDLIB_FILE"; exit 1; }
test -x /usr/local/bin/func || { echo "Missing /usr/local/bin/func"; exit 1; }
test -x /usr/local/bin/fift || { echo "Missing /usr/local/bin/fift"; exit 1; }

rm -f "$BUILD/ClaimDistributor.fif" "$BUILD/ClaimDistributor.boc"
rm -f "$ROOT/contracts/ClaimDistributor.boc" "$ROOT/ClaimDistributor.boc"

echo "=== DEBUG: PATHS ==="
echo "ROOT      = $ROOT"
echo "BUILD     = $BUILD"
echo "CONTRACT  = $CONTRACT"
echo "STDLIB    = $STDLIB_FILE"
echo "FUNC      = $(which func)"
echo "FIFT      = $(which fift)"
echo

echo "=== DEBUG: first 12 lines of contract ==="
sed -n '1,12p' "$CONTRACT"
echo

echo "=== DEBUG: compile Func -> Fift ==="
/usr/local/bin/func -v -SPA -I "$STDLIB_DIR" "$CONTRACT" -o "$BUILD/ClaimDistributor.fif"

test -s "$BUILD/ClaimDistributor.fif" || { echo "FIF not created"; exit 1; }

echo
echo "=== DEBUG: build Fift -> BOC ==="
/usr/local/bin/fift -I "$STDLIB_DIR" -s "$BUILD/ClaimDistributor.fif"

if [ -f "$ROOT/contracts/ClaimDistributor.boc" ]; then
  cp -f "$ROOT/contracts/ClaimDistributor.boc" "$BUILD/ClaimDistributor.boc"
fi

if [ -f "$ROOT/ClaimDistributor.boc" ]; then
  cp -f "$ROOT/ClaimDistributor.boc" "$BUILD/ClaimDistributor.boc"
fi

test -s "$BUILD/ClaimDistributor.boc" || { echo "BOC not created"; exit 1; }

echo
echo "=== OK ==="
ls -lh "$BUILD/ClaimDistributor.fif" "$BUILD/ClaimDistributor.boc"
sha256sum "$BUILD/ClaimDistributor.boc"
SH
chmod +x "$ROOT/build-native.sh"

echo "Patched:"
echo " - $ROOT/contracts/ClaimDistributor.fc"
echo " - $ROOT/build-native.sh"
