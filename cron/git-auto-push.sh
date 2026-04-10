#!/usr/bin/env bash
set -Eeuo pipefail

APP_REPO="/var/www/html/public/rwa"
META_REPO="/var/www/metadata-repo"
APP_BRANCH="main"
META_BRANCH="main"
LOG_FILE="/var/log/poado-git-auto-push.log"

timestamp() {
  date '+%Y-%m-%d %H:%M:%S'
}

log() {
  printf '[%s] %s\n' "$(timestamp)" "$*" >> "$LOG_FILE"
}

ensure_repo() {
  local repo_dir="$1"
  git -C "$repo_dir" rev-parse --is-inside-work-tree >/dev/null 2>&1
}

stage_app_whitelist() {
  local repo_dir="$1"

  local paths=(
    "cert/"
    "storage/"
    "swap/"
    "cron/"
    "api/"
    "assets/"
    "inc/"
    "index.php"
  )

  for p in "${paths[@]}"; do
    [ -e "$repo_dir/$p" ] && git -C "$repo_dir" add -- "$p" >/dev/null 2>&1 || true
  done
}

stage_metadata_repo() {
  local repo_dir="$1"
  git -C "$repo_dir" add -u
}

has_staged_changes() {
  local repo_dir="$1"
  ! git -C "$repo_dir" diff --cached --quiet
}

has_staged_deletions() {
  local repo_dir="$1"
  git -C "$repo_dir" diff --cached --name-status | grep -q '^D[[:space:]]'
}

log_staged_deletions() {
  local repo_dir="$1"
  local label="$2"
  while IFS= read -r line; do
    [ -n "$line" ] && log "$label: blocked deletion -> $line"
  done < <(git -C "$repo_dir" diff --cached --name-status | grep '^D[[:space:]]' || true)
}

unstage_all() {
  local repo_dir="$1"
  git -C "$repo_dir" restore --staged . >/dev/null 2>&1 || true
}

sync_app_repo() {
  local repo_dir="$1"
  local branch="$2"

  ensure_repo "$repo_dir" || { log "app-repo: skip"; return 0; }

  stage_app_whitelist "$repo_dir"

  if ! has_staged_changes "$repo_dir"; then
    log "app-repo: no whitelisted changes"
    return 0
  fi

  if has_staged_deletions "$repo_dir"; then
    log_staged_deletions "$repo_dir" "app-repo"
    unstage_all "$repo_dir"
    log "app-repo: blocked because no-delete mode is enabled"
    return 0
  fi

  git -C "$repo_dir" commit -m "auto: sync app ($(timestamp))" >> "$LOG_FILE" 2>&1 || true
  git -C "$repo_dir" push origin "$branch" >> "$LOG_FILE" 2>&1
  log "app-repo: pushed"
}

sync_metadata_repo() {
  local repo_dir="$1"
  local branch="$2"

  ensure_repo "$repo_dir" || { log "metadata-repo: skip"; return 0; }

  stage_metadata_repo "$repo_dir"

  if ! has_staged_changes "$repo_dir"; then
    log "metadata-repo: no changes"
    return 0
  fi

  if has_staged_deletions "$repo_dir"; then
    log_staged_deletions "$repo_dir" "metadata-repo"
    unstage_all "$repo_dir"
    log "metadata-repo: blocked because no-delete mode is enabled"
    return 0
  fi

  git -C "$repo_dir" commit -m "auto: sync metadata ($(timestamp))" >> "$LOG_FILE" 2>&1 || true
  git -C "$repo_dir" push origin "$branch" >> "$LOG_FILE" 2>&1
  log "metadata-repo: pushed"
}

mkdir -p "$(dirname "$LOG_FILE")"
touch "$LOG_FILE"

log "START"

sync_app_repo "$APP_REPO" "$APP_BRANCH"
sync_metadata_repo "$META_REPO" "$META_BRANCH"

log "END"
log ""
