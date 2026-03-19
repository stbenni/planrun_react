# AI Serving Stack

Актуальная production-схема локальной LLM в проекте PlanRun.

## Архитектура

```text
planrun-backend (PHP)
  ├─ direct chat/review -> LMSTUDIO_BASE_URL
  └─ plan generation + RAG -> planrun-ai :8000

planrun-ai (FastAPI)
  ├─ /api/v1/chat -> llama-server :8081
  ├─ /api/v1/generate-plan -> llama-server :8081
  └─ /api/v1/retrieve-knowledge -> LM Studio embeddings :1234 -> Qdrant

llama-server :8081
  └─ mistralai/ministral-3-14b-reasoning

LM Studio :1234
  └─ text-embedding-nomic-embed-text-v1.5
```

## Почему так

- Убираем model switching между чатом и генерацией планов.
- Reasoning-модель живёт в `llama-server`, который стабильнее под длинный decode на одной GPU.
- LM Studio остаётся только для embeddings и не держит тяжёлую chat-модель в памяти.
- `planrun-ai` отвечает за RAG, compact JSON prompt, парсинг ответа и fallback-валидацию.

## Deploy

Полная установка:

```bash
sudo ./deploy/install-llama-serving-stack.sh
```

Что ставится:

- [planrun-llama-server-start.sh](/var/www/planrun/deploy/planrun-llama-server-start.sh)
- [llama-server.service](/var/www/planrun/deploy/llama-server.service)
- [planrun-ai.service](/var/www/planrun/deploy/planrun-ai.service)
- [lm-studio.service.d/load-models.conf](/var/www/planrun/deploy/lm-studio.service.d/load-models.conf)
- [load-planrun-models.sh](/var/www/planrun/deploy/load-planrun-models.sh)

## Runtime endpoints

- `http://127.0.0.1:8081/v1` — OpenAI-compatible chat/completions для reasoning-модели
- `http://127.0.0.1:1234/v1` — OpenAI-compatible embeddings
- `http://127.0.0.1:8000` — PlanRun AI API

## Проверка после деплоя

```bash
systemctl status llama-server planrun-ai lm-studio --no-pager
curl -s http://127.0.0.1:8081/v1/models
curl -s http://127.0.0.1:1234/v1/models
curl -s http://127.0.0.1:8000/health
```

Smoke-тест plan generation:

```bash
curl -s http://127.0.0.1:8000/api/v1/generate-plan \
  -H 'Content-Type: application/json' \
  -d '{"user_id":1,"goal_type":"race","include_knowledge":true,"temperature":0.3,"max_tokens":6000,"base_prompt":"..."}'
```

## Важные env

В `planrun-ai.service`:

- `LMSTUDIO_BASE_URL=http://127.0.0.1:8081/v1`
- `LMSTUDIO_CHAT_MODEL=mistralai/ministral-3-14b-reasoning`
- `LMSTUDIO_PLAN_MODEL=mistralai/ministral-3-14b-reasoning`
- `PLANRUN_STRUCTURED_OUTPUT=false`
- `LMSTUDIO_EMBED_URL=http://127.0.0.1:1234/v1`
- `LMSTUDIO_EMBED_MODEL=text-embedding-nomic-embed-text-v1.5`

В `planrun-backend/.env`:

- `PLANRUN_AI_API_URL=http://127.0.0.1:8000/api/v1/generate-plan`
- Если chat должен идти через orchestration layer: `CHAT_USE_PLANRUN_AI=1`
- Если chat идёт напрямую: `LMSTUDIO_BASE_URL` можно держать на `:1234` или перевести на `:8081` после проверки tool-calling сценариев

## Текущие ограничения

- Планогенерация уже работает end-to-end, но остаётся quality tuning поверх validator/policy слоя.
- Для планов используется compact JSON text mode, а не strict structured output.
- Если direct chat в backend использует tools, переключение `LMSTUDIO_BASE_URL` на `:8081` нужно отдельно прогонять на tool-calling smoke-тестах.
