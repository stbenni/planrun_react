#!/bin/bash
# Загрузка embedding-модели PlanRun в LM Studio.
# Чат и планогенерация теперь идут через standalone llama-server,
# а LM Studio остаётся только для RAG-эмбеддингов.
# Использование: ./load-planrun-models.sh
# Или через systemd ExecStartPost после lm-studio.service

LM_API="http://127.0.0.1:1234"
EMBED_MODEL="text-embedding-nomic-embed-text-v1.5"

# Ждём готовности API (до 60 сек)
for i in $(seq 1 30); do
  if curl -sf "$LM_API/v1/models" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

# Загрузить embedding-модель (для RAG)
echo "[load-planrun-models] Loading $EMBED_MODEL..."
curl -sf -X POST "$LM_API/api/v1/models/load" \
  -H "Content-Type: application/json" \
  -d "{\"model\": \"$EMBED_MODEL\"}"

echo "[load-planrun-models] Done."
