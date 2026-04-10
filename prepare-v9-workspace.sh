#!/usr/bin/env bash
set -euo pipefail

BASE="/var/www/html/public/rwa/ton-v9"

mkdir -p "$BASE"/contracts
mkdir -p "$BASE"/wrappers
mkdir -p "$BASE"/scripts
mkdir -p "$BASE"/tests
mkdir -p "$BASE"/build
mkdir -p "$BASE"/temp
mkdir -p "$BASE"/docs

printf '%s\n' "Prepared V9 workspace:"
find "$BASE" -maxdepth 2 -type d | sort
