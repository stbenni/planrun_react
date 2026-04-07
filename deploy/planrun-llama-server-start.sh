#!/bin/bash
set -euo pipefail

BIN="${PLANRUN_LLAMASERVER_BIN:-/var/www/ai/planrun_ai_service/llama.cpp/build-cuda/bin/llama-server}"
MODEL="${PLANRUN_LLAMASERVER_MODEL:-/home/st_benni/.lmstudio/models/lmstudio-community/Qwen3-14B-GGUF/Qwen3-14B-Q4_K_M.gguf}"
ALIAS="${PLANRUN_LLAMASERVER_ALIAS:-qwen3-14b}"
HOST="${PLANRUN_LLAMASERVER_HOST:-127.0.0.1}"
PORT="${PLANRUN_LLAMASERVER_PORT:-8081}"
CTX="${PLANRUN_LLAMASERVER_CTX:-32768}"
THREADS="${PLANRUN_LLAMASERVER_THREADS:-16}"
THREADS_BATCH="${PLANRUN_LLAMASERVER_THREADS_BATCH:-16}"
BATCH="${PLANRUN_LLAMASERVER_BATCH:-1024}"
UBATCH="${PLANRUN_LLAMASERVER_UBATCH:-512}"
PARALLEL="${PLANRUN_LLAMASERVER_PARALLEL:-1}"
GPU_LAYERS="${PLANRUN_LLAMASERVER_GPU_LAYERS:-999}"

if [ ! -x "$BIN" ]; then
  echo "llama-server binary not found: $BIN" >&2
  exit 1
fi

if [ ! -f "$MODEL" ]; then
  echo "GGUF model not found: $MODEL" >&2
  exit 1
fi

exec "$BIN" \
  --host "$HOST" \
  --port "$PORT" \
  --model "$MODEL" \
  --alias "$ALIAS" \
  --ctx-size "$CTX" \
  --threads "$THREADS" \
  --threads-batch "$THREADS_BATCH" \
  --batch-size "$BATCH" \
  --ubatch-size "$UBATCH" \
  --parallel "$PARALLEL" \
  --gpu-layers "$GPU_LAYERS" \
  --flash-attn on \
  --metrics
