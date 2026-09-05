#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

prompt_yes_no() {
  local prompt="$1"
  local response=""
  while true; do
    read -r -p "$prompt [y/N] " response
    case "${response:-n}" in
      [Yy]|[Yy][Ee][Ss])
        return 0
        ;;
      [Nn]|"")
        return 1
        ;;
      *)
        echo "Please answer yes or no."
        ;;
    esac
  done
}

ENABLE_HEADLESS=false

if prompt_yes_no "Start the optional headless frontend?"; then
  ENABLE_HEADLESS=true
fi

COMPOSE_ARGS=( -f compose.yaml )
if [[ "$ENABLE_HEADLESS" == true ]]; then
  COMPOSE_ARGS+=( --profile headless )
fi
COMPOSE_ARGS+=( up -d )

echo "Starting World Graph Studio with headless=$ENABLE_HEADLESS"
docker compose "${COMPOSE_ARGS[@]}"
