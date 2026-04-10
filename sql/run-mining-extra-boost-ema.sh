#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${1:-wems_db}"
SQL_FILE="/var/www/html/public/rwa/sql/mining-extra-boost-ema.sql"

echo "Running SQL: $SQL_FILE on DB: $DB_NAME"
mysql "$DB_NAME" < "$SQL_FILE"
echo "Done."
