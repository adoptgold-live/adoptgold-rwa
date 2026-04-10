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

has_staged_changes() {
  local repo_dir="$1"
  ! git -C "$repo_dir" diff --cached --quiet
}

sync_app_repo() {
  local repo_dir="$1"
  local branch="$2"

  ensure_repo "$repo_dir" || { log "app-repo: skip"; return; }

  stage_app_whitelist "$repo_dir"

  if ! has_staged_changes "$repo_dir"; then
    log "app-repo: no whitelisted changes"
    return
  fi

  git -C "$repo_dir" commit -m "auto: sync app ($(timestamp))" >> "$LOG_FILE" 2>&1 || true
  git -C "$repo_dir" push origin "$branch" >> "$LOG_FILE" 2>&1
  log "app-repo: pushed"
}

sync_metadata_repo() {
  local repo_dir="$1"
  local branch="$2"

  ensure_repo "$repo_dir" || { log "metadata-repo: skip"; return; }

  git -C "$repo_dir" add -u

  if git -C "$repo_dir" diff --cached --quiet; then
    log "metadata-repo: no changes"
    return
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
