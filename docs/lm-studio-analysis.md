# Полный анализ конфигурации LM Studio для PlanRun

**Дата:** 2026-03-07

---

## 1. Архитектура

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           vladimirov (PlanRun)                               │
└─────────────────────────────────────────────────────────────────────────────┘
         │
         │ CHAT_USE_PLANRUN_AI=0 → напрямую
         │ CHAT_RAG_ENABLED=1 → RAG через PlanRun AI
         ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  planrun-backend (PHP)                                                       │
│  ChatService → LM Studio :1234 (chat)                                        │
│  ChatService → PlanRun AI :8000 (RAG: retrieve-knowledge)                     │
└─────────────────────────────────────────────────────────────────────────────┘
         │                              │
         │ chat                         │ RAG
         ▼                              ▼
┌──────────────────────┐    ┌──────────────────────────────────────────────────────┐
│  LM Studio :1234     │    │  PlanRun AI :8000                                     │
│  - llmster (API)     │    │  retrieve-knowledge → get_query_embedding()           │
│  - /v1/chat/         │    │    → LM Studio /v1/embeddings (LMSTUDIO_EMBED_URL)     │
│  - /v1/embeddings    │◄───│  chat → LM Studio /v1/chat/completions (fallback)      │
└──────────────────────┘    │  generate-plan → LM Studio (планы)                    │
         │                  └──────────────────────────────────────────────────────┘
         │                              │
         │                              │
         ▼                              ▼
┌──────────────────────┐    ┌──────────────────────────────────────────────────────┐
│  Модели:             │    │  Qdrant :6333                                          │
│  - llm: ministral    │    │  коллекция planrun_knowledge (232 794 точек)           │
│  - embed: nomic      │    │  эмбеддинги от ingest_knowledge.py                    │
└──────────────────────┘    └──────────────────────────────────────────────────────┘
```

---

## 2. Конфигурация по компонентам

### 2.1 vladimirov/planrun-backend (.env)

| Переменная | Пример | Назначение |
|------------|--------|------------|
| `LMSTUDIO_BASE_URL` | `http://127.0.0.1:1234/v1` | URL LM Studio API |
| `LMSTUDIO_CHAT_MODEL` | `mistralai/ministral-3-14b-reasoning` | Модель для чата |
| `CHAT_RAG_ENABLED` | `1` | Включить RAG (подстановка фрагментов из базы знаний) |
| `CHAT_USE_PLANRUN_AI` | `0` | 0 = напрямую LM Studio (tools работают) |
| `CHAT_FALLBACK_TO_PLANRUN_AI` | `1` | Fallback при ошибке LM Studio |
| `PLANRUN_AI_API_URL` | `http://127.0.0.1:8000/api/v1/generate-plan` | URL PlanRun AI (RAG выводится из него) |

`RAG_RETRIVE_URL` = `PLANRUN_AI_API_URL` с заменой `/generate-plan` → `/retrieve-knowledge` = `http://127.0.0.1:8000/api/v1/retrieve-knowledge`

### 2.2 PlanRun AI (planrun-ai.service + .env)

| Переменная | Значение | Назначение |
|------------|----------|------------|
| `LMSTUDIO_BASE_URL` | `http://localhost:1234/v1` | Чат, генерация планов |
| `LMSTUDIO_CHAT_MODEL` | `mistralai/ministral-3-14b-reasoning` | Чат |
| `LMSTUDIO_PLAN_MODEL` | `mistralai/ministral-3-14b-reasoning` | Генерация планов |
| `LMSTUDIO_EMBED_URL` | `http://localhost:1234/v1` | Эмбеддинги для RAG |
| `LMSTUDIO_EMBED_MODEL` | `text-embedding-nomic-embed-text-v1.5:2` | Модель эмбеддингов |
| `QDRANT_HOST` | `localhost` | Qdrant |
| `QDRANT_PORT` | `6333` | |
| `QDRANT_COLLECTION` | `planrun_knowledge` | Коллекция RAG |

**⚠️ Важно:** `LMSTUDIO_EMBED_MODEL=text-embedding-nomic-embed-text-v1.5:2` — суффикс `:2` может не совпадать с ID модели в LM Studio. Проверка: `lms ls` показывает `text-embedding-nomic-embed-text-v1.5`. Если модель не загружается по этому ID, попробуйте без `:2`.

---

## 3. systemd-сервисы

### 3.1 lm-studio.service (в /etc/systemd/system/)

| Параметр | Значение |
|----------|----------|
| Путь | `/etc/systemd/system/lm-studio.service` |
| Type | `simple` |
| ExecStart | `/home/st_benni/.lmstudio/bin/lms server start` |
| Статус | **inactive (dead)** — сервис завершается после старта |

**Проблема:** `lms server start` запускает llmster в фоне и завершается. systemd считает сервис остановленным.

### 3.2 lmstudio.service (в altervision/server/)

| Параметр | Значение |
|----------|----------|
| Путь | `/var/www/altervision/server/lmstudio.service` |
| Type | `oneshot` + `RemainAfterExit=yes` |
| ExecStart | `lms daemon up` + `lms server start` |
| ExecStartPost | `load-ministral-32k.sh` (загрузка Ministral) |
| Статус | **failed** |

**Содержимое load-ministral-32k.sh:**
- Ждёт готовности API (до 60 сек)
- Выгружает текущую модель (если есть)
- Загружает `mistralai/ministral-3-14b-reasoning` с context_length=32768

**Скрипт не загружает:** модель эмбеддингов `text-embedding-nomic-embed-text-v1.5` — для RAG нужна отдельная загрузка.

### 3.3 planrun-ai.service

| Параметр | Значение |
|----------|----------|
| Requires | `lm-studio.service` |
| ExecStartPre | Ждёт `curl -sf http://127.0.0.1:1234/v1/models` до 60 сек |
| Статус | **active (running)** |

**Проблема ExecStartPre:** проверка `curl -sf .../v1/models` возвращает успех при HTTP 200, даже если `{"data":[]}` (модели не загружены). PlanRun AI стартует без проверки наличия моделей.

---

## 4. Модели в LM Studio

| Тип | Модель | Назначение | Статус на диске |
|-----|--------|------------|-----------------|
| LLM | `mistralai/ministral-3-14b-reasoning` | Чат, планы | ✅ Есть |
| Embedding | `text-embedding-nomic-embed-text-v1.5` | RAG | ✅ Есть |
| LLM | `qwen/qwen3.5-9b` | Альтернатива | ✅ Есть |
| LLM | `openai/gpt-oss-20b` | Альтернатива | ✅ Есть |

---

## 5. Цепочка вызовов

### Чат (без fallback)

1. ChatScreen → ApiClient.chatSendMessageStream()
2. api_wrapper.php?action=chat_send_message_stream
3. ChatController::sendMessageStream()
4. ChatService::streamResponse()
   - resolveToolCalls() → callLlmDirect() → LM Studio :1234/v1/chat/completions
   - callLlmStream() → callLlmStreamDirect() → LM Studio :1234/v1/chat/completions
5. **Требует:** загруженную модель `mistralai/ministral-3-14b-reasoning`

### RAG

1. ChatService::appendRagSnippet() (при CHAT_RAG_ENABLED=1)
2. POST PlanRun AI /api/v1/retrieve-knowledge
3. PlanRun AI retrieve_knowledge() → get_query_embedding(query)
4. get_query_embedding → LM Studio :1234/v1/embeddings (LMSTUDIO_EMBED_URL)
5. **Требует:** загруженную модель `text-embedding-nomic-embed-text-v1.5`
6. Поиск в Qdrant по вектору

### Fallback (при ошибке LM Studio)

1. ChatService::callLlmStream() catch → callPlanRunAIChatStream()
2. PlanRun AI /api/v1/chat
3. PlanRun AI _stream_lmstudio_chat() → LM Studio :1234/v1/chat/completions
4. **Fallback тоже зависит от LM Studio** — если LM Studio недоступен, fallback не поможет.

---

## 6. Выявленные проблемы

### 6.1 Модели не загружаются при старте

- `lm-studio.service` только запускает API, модели не загружает.
- `lmstudio.service` (altervision) с `load-ministral-32k.sh` в failed — загрузка Ministral не выполняется.
- `load-ministral-32k.sh` загружает только **чат-модель**, не загружает **embedding-модель** для RAG.

### 6.2 Два разных LM Studio unit

- `lm-studio.service` — активный в systemd
- `lmstudio.service` — failed, используется altervision

### 6.3 Несоответствие ID модели эмбеддингов

- В planrun-ai.service: `text-embedding-nomic-embed-text-v1.5:2`
- В LM Studio: `text-embedding-nomic-embed-text-v1.5` (без :2)
- При несовпадении ID эмбеддинги могут не работать.

---

## 7. Рекомендации

### 7.1 Унифицировать загрузку моделей

Создать скрипт `load-planrun-models.sh`:

```bash
#!/bin/bash
LM_API="http://127.0.0.1:1234"

# Ждём API
for i in $(seq 1 30); do
  curl -sf "$LM_API/v1/models" >/dev/null 2>&1 && break
  sleep 2
done

# 1. Загрузить чат-модель
curl -sf -X POST "$LM_API/api/v1/models/load" \
  -H "Content-Type: application/json" \
  -d '{"model":"mistralai/ministral-3-14b-reasoning","context_length":32768,"flash_attention":true}'

# 2. Загрузить embedding-модель (для RAG)
curl -sf -X POST "$LM_API/api/v1/models/load" \
  -H "Content-Type: application/json" \
  -d '{"model":"text-embedding-nomic-embed-text-v1.5"}'
```

### 7.2 Добавить ExecStartPost в lm-studio.service

Или создать отдельный сервис `lm-studio-load-models.service` (Type=oneshot), который запускается после lm-studio и вызывает скрипт загрузки.

### 7.3 Проверить LMSTUDIO_EMBED_MODEL

В planrun-ai.service попробовать без `:2`:
```
Environment=LMSTUDIO_EMBED_MODEL=text-embedding-nomic-embed-text-v1.5
```

### 7.4 Ручная проверка

```bash
# 1. Загрузить модели
curl -X POST http://127.0.0.1:1234/api/v1/models/load \
  -H "Content-Type: application/json" \
  -d '{"model":"mistralai/ministral-3-14b-reasoning","context_length":32768}'

curl -X POST http://127.0.0.1:1234/api/v1/models/load \
  -H "Content-Type: application/json" \
  -d '{"model":"text-embedding-nomic-embed-text-v1.5"}'

# 2. Проверить
curl -s http://127.0.0.1:1234/v1/models
curl -sf -X POST http://127.0.0.1:8000/api/v1/retrieve-knowledge \
  -H "Content-Type: application/json" \
  -d '{"query":"темповый бег","limit":2}'
```

---

## 8. Исправления (2026-03-07)

Созданы файлы в `deploy/`:

- **load-planrun-models.sh** — загружает Ministral + nomic-embed в LM Studio
- **lm-studio.service.d/load-models.conf** — ExecStartPost для автозагрузки при старте lm-studio
- **planrun-ai.service.d/embed-model-fix.conf** — исправление LMSTUDIO_EMBED_MODEL (без :2)
- **install-lm-studio-models.sh** — установка drop-in'ов: `sudo ./deploy/install-lm-studio-models.sh`

После установки модели загружаются автоматически при перезапуске lm-studio.service.

## 9. Текущее состояние

| Компонент | Статус |
|-----------|--------|
| LM Studio API :1234 | ✅ Работает |
| Ministral (chat) | ✅ Загружена |
| Nomic (embed) | ✅ Загружена |
| RAG retrieve-knowledge | ✅ Работает |
| Qdrant planrun_knowledge | ✅ 232 794 точек |
