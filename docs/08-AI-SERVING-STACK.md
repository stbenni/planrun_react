# AI Serving Stack

Актуальная production-схема LLM в проекте PlanRun.

## Архитектура

```text
planrun-backend (PHP)
  ├─ chat / proactive coach / review -> DeepSeek API (deepseek-v4-flash)
  ├─ plan generation (llm_planner)   -> DeepSeek API (deepseek-v4-pro + deepseek-v4-flash)
  └─ все LLM-запросы                 -> LlmGateway (retry, concurrency limiter, key pool)

DeepSeek API (https://api.deepseek.com)
  ├─ deepseek-v4-pro   — planner macro/repair, reviewer
  └─ deepseek-v4-flash — chat, enricher, detail expansion
```

## Почему так

- Облачный DeepSeek API вместо локального llama-server: стабильнее, быстрее, не требует GPU.
- `LlmGateway` абстрагирует провайдера: retry с exponential backoff, DB-backed concurrency limiter, пул API ключей.
- Планогенерация через `DeepSeekPlanPlanner` (macro -> detail -> repair).
- Chat и coaching используют `deepseek-v4-flash` для скорости.

## Важные env

В `planrun-backend/.env`:

```bash
# Провайдер и endpoint
LLM_PROVIDER=deepseek
LLM_CHAT_BASE_URL=https://api.deepseek.com
LLM_CHAT_MODEL=deepseek-v4-flash

# API ключи (пул через запятую/пробел)
PLAN_LLM_API_KEY=sk-...
# PLAN_LLM_API_KEYS=sk-key1,sk-key2

# Планогенерация (после Phase A: одна модель для DeepSeek single_pass)
PLAN_GENERATION_MODE=llm_planner
PLAN_LLM_MODEL=deepseek-chat
# Следующие env DEPRECATED после PR2 (читаются только как fallback):
# PLAN_LLM_PLANNER_MODEL, PLAN_LLM_DETAIL_MODEL, PLAN_LLM_REPAIR_MODEL,
# PLAN_LLM_ENRICHER_MODEL, PLAN_LLM_REVIEWER_MODEL, PLAN_LLM_PLANNER_STRATEGY,
# USE_SKELETON_GENERATOR.

# Concurrency limiter
# LLM_GATEWAY_GLOBAL_MAX_CONCURRENT=8
# PLAN_LLM_MAX_CONCURRENT=3
# LLM_CHAT_MAX_CONCURRENT=5
```

## Проверка

```bash
# Статус воркеров
systemctl status planrun-plan-generation-worker@{1,2,3} --no-pager

# Лог LLM-запросов в БД
mysql -e "SELECT surface, event_type, status, JSON_EXTRACT(payload,'$.model') AS model, duration_ms FROM ai_runtime_events ORDER BY id DESC LIMIT 10" sv
```

## Legacy: локальный llama-server

Скрипты для локального стека остались в `deploy/` для справки, но не используются в production:

- `install-llama-serving-stack.sh` — llama-server + planrun-ai + LM Studio
- `planrun-ai :8000` — Python orchestration layer, RAG (при `PLAN_GENERATION_MODE` != `llm_planner`)

## Текущие ограничения

- Планогенерация работает end-to-end через DeepSeek, но остаётся quality tuning поверх validator/policy слоя.
- Для планов используется compact JSON text mode, а не strict structured output.
