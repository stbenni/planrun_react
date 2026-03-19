#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

install -m 0755 "$SCRIPT_DIR/planrun-llama-server-start.sh" /usr/local/bin/planrun-llama-server-start.sh
install -m 0644 "$SCRIPT_DIR/llama-server.service" /etc/systemd/system/llama-server.service

mkdir -p /etc/systemd/system/lm-studio.service.d
install -m 0644 "$SCRIPT_DIR/lm-studio.service.d/load-models.conf" /etc/systemd/system/lm-studio.service.d/load-models.conf

install -m 0644 "$SCRIPT_DIR/planrun-ai.service" /etc/systemd/system/planrun-ai.service

mkdir -p /etc/systemd/system/planrun-ai.service.d
cat > /etc/systemd/system/planrun-ai.service.d/boot-resilience.conf <<'EOF'
[Unit]
Requires=
After=network.target llama-server.service lm-studio.service
EOF

systemctl daemon-reload
systemctl enable llama-server.service
systemctl enable planrun-ai.service
systemctl restart lm-studio.service
systemctl restart llama-server.service
systemctl restart planrun-ai.service
