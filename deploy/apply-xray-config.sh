#!/usr/bin/env bash
# Применить deploy/xray-config.json → /usr/local/etc/xray/config.json и перезапустить xray.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CFG="${ROOT}/xray-config.json"
TARGET="/usr/local/etc/xray/config.json"

if [[ "${EUID:-0}" -ne 0 ]]; then
  exec sudo bash "$0" "$@"
fi

[[ -f "$CFG" ]] || { echo "Нет $CFG" >&2; exit 1; }
mkdir -p /usr/local/etc/xray
cp -a "$CFG" "$TARGET"
/usr/local/bin/xray run -test -config "$TARGET"
systemctl restart xray.service
sleep 1
systemctl is-active xray.service && echo "xray: active" || exit 1
