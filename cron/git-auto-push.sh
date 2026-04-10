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
    if [ -e "$repo_dir/$p" ]; then
      git -C "$repo_dir" add -- "$p" >/dev/null 2>&1 || true
    fi
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

sync_app_repo() {
  local repo_dir="$1"
  local branch="$2"

  if ! ensure_repo "$repo_dir"; then
    log "app-repo: skip (not a git repo: $repo_dir)"
    return 0
  fi

  stage_app_whitelist "$repo_dir"

  if ! has_staged_changes "$repo_dir"; then
    log "app-repo: no whitelisted staged changes"
    return 0
  fi

  git -C "$repo_dir" commit -m "auto: sync app changes ($(timestamp))" >> "$LOG_FILE" 2>&1 || true
  git -C "$repo_dir" push origin "$branch" >> "$LOG_FILE" 2>&1
  log "app-repo: pushed whitelisted changes to origin/$branch"
}

sync_metadata_repo() {
  local repo_dir="$1"
  local branch="$2"

  if ! ensure_repo "$repo_dir"; then
    log "metadata-repo: skip (not a git repo: $repo_dir)"
    return 0
  fi

  stage_metadata_repo "$repo_dir"

  if ! has_staged_changes "$repo_dir"; then
    log "metadata-repo: no tracked changes"
    return 0
  fi

  git -C "$repo_dir" commit -m "auto: sync metadata ($(timestamp))" >> "$LOG_FILE" 2>&1 || true
  git -C "$repo_dir" push origin "$branch" >> "$LOG_FILE" 2>&1
  log "metadata-repo: pushed tracked changes to origin/$branch"
}

mkdir -p "$(dirname "$LOG_FILE")"
touch "$LOG_FILE"

log "START git auto push"

sync_app_repo "$APP_REPO" "$APP_BRANCH"
sync_metadata_repo "$META_REPO" "$META_BRANCH"

log "END git auto push"
log ""
