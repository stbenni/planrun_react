#!/bin/bash
# Загрузка моделей PlanRun в LM Studio: Ministral (чат) + nomic (RAG эмбеддинги)
# Использование: ./load-planrun-models.sh
# Или через systemd ExecStartPost после lm-studio.service

LM_API="http://127.0.0.1:1234"
CHAT_MODEL="mistralai/ministral-3-14b-reasoning"
EMBED_MODEL="text-embedding-nomic-embed-text-v1.5"
CONTEXT=32768

# Ждём готовности API (до 60 сек)
for i in $(seq 1 30); do
  if curl -sf "$LM_API/v1/models" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

# 1. Загрузить чат-модель (Ministral)
echo "[load-planrun-models] Loading $CHAT_MODEL..."
curl -sf -X POST "$LM_API/api/v1/models/load" \
  -H "Content-Type: application/json" \
  -d "{\"model\": \"$CHAT_MODEL\", \"context_length\": $CONTEXT, \"flash_attention\": true}"

# 2. Загрузить embedding-модель (для RAG)
echo "[load-planrun-models] Loading $EMBED_MODEL..."
curl -sf -X POST "$LM_API/api/v1/models/load" \
  -H "Content-Type: application/json" \
  -d "{\"model\": \"$EMBED_MODEL\"}"

echo "[load-planrun-models] Done."
