# PlanRun AI Pipeline — построчный аудит

Дата старта: 2026-05-15. Branch: `main` (uncommitted WIP с Dashboard виджетами не входит в скоуп этого аудита).

Этот документ — построчный аудит backend AI-пайплайна PlanRun. Цель — зафиксировать архитектуру, ответственность каждого файла, найденные дефекты (баги/security/dead code/нарушения паттернов) и точки технического долга.

## Структура аудита

- **Phase 1 — Chat подсистема** (~6 100 строк, 9 файлов): входная точка AI-чата, контекст диалога, реестр инструментов, бэкенд работы с LLM.
- **Phase 2 — Plan generation** (~10 000 строк, 14 файлов): legacy-папка `planrun_ai/*` + новый pipeline `PlanSkeletonBuilder` → `PlanGenerationProcessorService` → `PlanQualityGate`.
- **Phase 3 — Coaching + observability** (~3 000 строк, 7 файлов): проактивный коуч, post-workout followup, общий CoachService, AI-метрики.

Для каждого файла даётся: назначение, контракт (что принимает/возвращает), ключевые методы (с цитированием строк), найденные проблемы, рекомендации.

Условные обозначения важности:
- 🔴 **критично** — баг, security, потенциально data corruption
- 🟡 **средне** — несогласованность, неэффективность, фрагильный паттерн
- 🟢 **мелочь** — стилистика, излишество, документ

---

## Phase 1 — Chat подсистема

### Файлы фазы

| Файл | Строк | Роль |
|---|---|---|
| [`controllers/ChatController.php`](../planrun-backend/controllers/ChatController.php) | 564 | HTTP-точка входа для actions `chat_*` |
| [`services/ChatService.php`](../planrun-backend/services/ChatService.php) | 917 | Оркестратор: цикл LLM ↔ tool calls |
| [`services/ChatContextBuilder.php`](../planrun-backend/services/ChatContextBuilder.php) | 1290 | Сбор контекста: профиль, план, история, погода |
| [`services/ChatToolRegistry.php`](../planrun-backend/services/ChatToolRegistry.php) | 1079 | Реестр и исполнение tool-функций |
| [`services/ChatPromptBuilder.php`](../planrun-backend/services/ChatPromptBuilder.php) | 535 | Системный промпт + инструкции |
| [`services/ChatActionParser.php`](../planrun-backend/services/ChatActionParser.php) | 206 | Sanitization + парсинг legacy ACTION-блоков |
| [`services/ChatConfirmationHandler.php`](../planrun-backend/services/ChatConfirmationHandler.php) | 448 | Двухшаговое подтверждение деструктивных операций |
| [`services/ChatMemoryManager.php`](../planrun-backend/services/ChatMemoryManager.php) | 236 | Краткосрочная и долгосрочная память диалога |
| [`services/LlmGateway.php`](../planrun-backend/services/LlmGateway.php) | 873 | Тонкий клиент DeepSeek API (chat + concurrency limiter) |

_Итого: 6 148 строк._

### Архитектура запроса

```
ChatController::sendMessageStream
   ├─ enforceChatRateLimit (30/min + 2s gap)
   ├─ releaseSessionLock (для concurrent SSE)
   └─ ChatService::streamResponse
        ├─ Repository::getOrCreateConversation + addMessage(user)
        ├─ PostWorkoutFollowupService::tryHandleUserReply  ─┐ если followup pending — короткий путь
        │                                                    └─ persistPostWorkoutFollowupReply → flush → return
        ├─ applyHistorySummarization (если history ≥ 35 сообщений)
        ├─ ChatContextBuilder::buildContextForUser
        │     ├─ formatProfile / formatPlanSummary / formatStats
        │     ├─ formatCoachingInsights (ACWR, compliance, load trend)
        │     ├─ formatRecentActivity / formatPlanHistoryAnalyses
        │     ├─ formatRecentWellness
        │     └─ + getUserMemory + getHistorySummary
        ├─ ChatPromptBuilder::appendChatSearchSnippet (поиск по chat history)
        ├─ ChatPromptBuilder::appendRagSnippet (RAG к PlanRun AI /retrieve-knowledge)
        ├─ ChatPromptBuilder::buildChatMessages
        │     ├─ buildCompressedSystemPrompt (cache-friendly: даты в конце user msg)
        │     ├─ addons: race replacement, add-training
        │     └─ normalizeMessagesForStrictAlternation
        ├─ ChatConfirmationHandler::tryHandleSwap/ReplaceRace/GenericUpdate
        │     └─ если поймал «да» к прошлому предложению — выполнить tool, замержить в messages
        ├─ checkLlmHealth (GET /models, 3s timeout)
        ├─ resolveToolCalls (loop до 5 раундов; стриминг marker `tool_executing`)
        ├─ NDJSON control lines: plan_updated, plan_recalculating, plan_generating_next
        ├─ callLlmStream → think-tag buffering → onChunk → NDJSON `chunk`
        ├─ actionParser::sanitizeResponse + parseAndExecuteActions
        ├─ repository::addMessage(ai)
        ├─ если connection_aborted → sendChatPush (FCM)
        └─ triggerMemoryExtraction (non-blocking)
```

В non-streaming пути (`sendMessageAndGetResponse`) **отсутствуют**: ConfirmationHandler, checkLlmHealth, triggerMemoryExtraction. См. **🔴 ChatService #2** ниже.

---

### `ChatController.php` (564 строк)

**Назначение**: HTTP-routing для actions с префиксом `chat_*`. Делегирует всю логику в `ChatService`. Обеспечивает rate-limiting и базовую sanitization входа.

**Эндпоинты**:
- `chat_get_messages` (L53) — пагинированный список сообщений (AI или admin).
- `chat_send_message` (L76) — non-stream AI-ответ.
- `chat_send_message_stream` (L148) — NDJSON стриминг ответа.
- `chat_clear_ai` (L189) — удалить всю историю AI-чата.
- `chat_get_latest_proactive_message` (L36) — последний briefing/insight для dashboard hero-card.
- `chat_send_message_to_admin` / `chat_send_message_to_user` / `chat_admin_send_message` / `chat_admin_broadcast` — мессаджинг между пользователями и админом.
- `chat_mark_read` / `chat_mark_all_read` / `chat_admin_mark_all_read` / `chat_admin_mark_conversation_read` — read receipts.
- `chat_add_ai_message` (L535) — admin-only досыл AI-сообщения произвольному пользователю.

**Лимиты**:
- L88, L161, L215, L276, L310, L437, L550: `mb_strlen ≤ 4000` для всех текстовых вводов.
- L60: list pagination ≤ 100, L385: admin unread ≤ 20.

**Найденные проблемы**:

- 🟡 **L111-142 (rate-limit)** — `enforceChatRateLimit` fail-open при ошибке prepare. Комментарий явно: «don't block users on infra hiccup». Решение продумано, но в инциденте может стать атакой через намеренный SQL-стресс. Можно оставить, документировать.
- 🟡 **L172-175 (стриминг headers)** — `header('Content-Type: application/x-ndjson')` + `X-Accel-Buffering: no` + `while (ob_get_level()) ob_end_clean()` — корректный паттерн для nginx, но **отсутствует `Connection: keep-alive` и `Cache-Control` хедер ставится после Content-Type**. Поскольку Cache-Control явно не задан — может попасть кэш CDN, если он есть. Добавить: `header('Cache-Control: no-cache, no-transform');`.
- 🟢 **L406** — `(int)($this->getParam('user_id') ?? 0)` использует `??`, тогда как L411 для лимита — `?:`. Несогласованность стиля внутри одной функции.
- 🟢 **L405-406** — `getAdminMessages` принимает `user_id` (одиночный); в `sendAdminMessage` (L299) поддерживает оба `user_id` и `target_user_id`. Гибкость для legacy клиентов, но множит варианты.
- 🟢 **L78, L150** — `set_time_limit(300)` дублируется, можно вынести в BaseController.

---

### `ChatService.php` (917 строк)

**Назначение**: Оркестратор всего AI-чата. Делегирует tools → `ChatToolRegistry`, prompt → `ChatPromptBuilder`, подтверждения → `ChatConfirmationHandler`, sanitize → `ChatActionParser`. Сам отвечает за: LLM-запросы (stream/non-stream), summarization истории, mesaging CRUD, push-уведомления.

**Ключевые методы**:
- `sendMessageAndGetResponse` (L202) — non-streaming flow.
- `streamResponse` (L242) — streaming flow.
- `callLlm` (L442) — non-stream LLM с tool loop (до 3 раундов, env `CHAT_MAX_TOOL_ROUNDS`).
- `callLlmStreamDirect` (L572) — стрим через cURL с per-chunk парсингом SSE → callback `onChunk`.
- `resolveToolCalls` (L392) — отдельная функция для streaming-режима, до 5 раундов tool calling.
- `applyHistorySummarization` (L140) — при ≥ 35 сообщений суммаризирует старые.
- `summarizeOlderMessages` (L156) — LLM-вызов с системным промптом для сжатия истории.
- `triggerMemoryExtraction` (L380) — non-blocking вызов `ChatMemoryManager`.

**Найденные проблемы**:

- 🔴 **#1. `message_id` — потенциально неверный ID** ([ChatService.php:228, 237](../planrun-backend/services/ChatService.php#L228-L237))
  ```php
  $this->repository->addMessage(...);  // L228, возвращает int но не сохраняется
  ...
  return ['content' => $fullContent, 'message_id' => $this->db->insert_id ?? null]; // L237
  ```
  `addMessage` возвращает корректный ID — он не сохраняется. Чтение `$this->db->insert_id` после возврата отдаёт ID **последнего** INSERT на этом mysqli connection. Если `addMessage` внутри сделал несколько INSERT (например, metadata в отдельную таблицу) или вызвал что-то ещё — вернёшь ID не нужной строки. **Фронт получит неверный message_id**, чем потом ломается scroll-to / mark-as-read.
  **Фикс**: `$messageId = $this->repository->addMessage(...); return ['content' => $fullContent, 'message_id' => $messageId];`

- 🔴 **#2. Memory extraction и confirmation handlers отсутствуют в non-stream-пути**
  - `streamResponse` (L375) вызывает `triggerMemoryExtraction`. `sendMessageAndGetResponse` (L202) — **нет**. Пользователи, у которых фронт использует non-streaming endpoint (например, fallback при HTTP/1.1 без SSE) **не получат обновления памяти**.
  - Аналогично `confirmationHandler->tryHandleSwap/ReplaceRace/GenericUpdate` (L275-282) — только в streaming. В non-stream «да» к предложению AI не будет распознано и не выполнится.
  - `checkLlmHealth` (L285) — тоже только stream.
  **Фикс**: вынести pre/post-обработку в общий helper, вызывать из обоих путей.

- 🟡 **#3. Variable used before declaration** ([ChatService.php:468, 491](../planrun-backend/services/ChatService.php#L468-L491))
  ```php
  'tools_used' => array_values(array_unique($toolsUsedAccum ?? [])),  // L468
  ...
  $toolsUsedAccum = array_merge($toolsUsedAccum ?? [], $roundToolNames);  // L491
  ```
  `$toolsUsedAccum` объявляется ниже первого использования; `?? []` спасает, но это код-смелл. Объявить перед циклом: `$toolsUsedAccum = [];`

- 🟡 **#4. LLM health check на каждый stream** ([ChatService.php:285](../planrun-backend/services/ChatService.php#L285))
  GET `/models` на DeepSeek = 100-500ms latency на КАЖДЫЙ стрим. Кэшировать с TTL 30-60s (APCu или MySQL).

- 🟡 **#5. PlanRun AI fallback URL transform хрупкий** ([ChatService.php:541, 707](../planrun-backend/services/ChatService.php#L541-L707))
  ```php
  $url = preg_replace('#/generate-plan$#', '/chat', $base);
  ```
  Если `PLANRUN_AI_API_URL` без `/generate-plan` в конце — URL останется без изменений и сломается. Лучше задать `PLANRUN_AI_BASE_URL` + явные path-suffix константы.

- 🟡 **#6. Think-tag buffer без верхней границы** ([ChatService.php:321-358](../planrun-backend/services/ChatService.php#L321-L358))
  Если модель эмитит `[THINK` без закрывающего тега, `$thinkBuffer` растёт неограниченно до конца стрима. На длинном reasoning может занять много памяти.
  **Фикс**: добавить cap (например, 50KB), при превышении — flush как обычный текст.

- 🟡 **#7. `array_intersect` как bool** ([ChatService.php:298](../planrun-backend/services/ChatService.php#L298))
  ```php
  if (array_intersect($planChangeTools, $toolsUsed)) { ... }
  ```
  Работает, но менее читаемо чем `!empty(array_intersect(...))`. Минор.

- 🟢 **#8. `$planChangeTools` массив-литерал внутри метода** (L297) — вынести в const на уровне класса.

- 🟢 **#9. `getAdminUserIds`** (L897) использует raw `query`; `getUsernameById` (L888) — `prepare`. Style mismatch (но в L897 SQL без user input — безопасно).

- 🟢 **#10. Дублирование** — `mb_strlen($body) > 100 ? mb_substr(..., 97).'...' : $body` повторяется (L864, L877). Вынести в `BaseService::truncate()`.

---

### `LlmGateway.php` (873 строк)

**Назначение**: статический фасад для DeepSeek/OpenAI-compatible API. Берёт на себя: rotation API-ключей, формат `thinking`-mode, retry с backoff и Retry-After, concurrency limiter через MySQL `GET_LOCK` + табличные leases, observability.

**Ключевые методы**:
- `requestChatCompletion` (L176) → `requestJson` (L181) — основной entrypoint.
- `withThinkingMode` (L157) — нормализует payload под провайдер.
- `acquireConcurrencyLease` / `releaseConcurrencyLease` (L376, L427) — рантайм-лимитер.
- `apiKey` / `apiKeys` (L71, L81) — поддерживает per-purpose ключи (`PLAN_LLM_*`, `LLM_CHAT_*`, `DEEPSEEK_*`).
- `queueRetryDelaySeconds` (L736) — для воркера очереди генерации плана.
- `LlmGatewayRequestException` (L10) — несёт `httpStatus`, `retryable`, `retryAfterSeconds`, `responseBody`.

**Хорошо**:
- Корректные TCP keepalive (L254-256) для длинных reasoning запросов (4-5 мин на DeepSeek-V4).
- Retry-After header парсится и для секунд, и для HTTP-date (L762-783).
- Concurrency limiter с GET_LOCK + INSERT — race-safe.
- Auto-create таблицы лимитера при первом обращении (L598-623).
- Sanitization observability payload (L865-872) — удаляются ключ, сами сообщения, response body — НЕТ утечки PII в метрики.

**Найденные проблемы**:

- 🔴 **#11. `sleepBeforeRetry` clamp игнорирует длинный Retry-After** ([LlmGateway.php:797-800](../planrun-backend/services/LlmGateway.php#L797-L800))
  ```php
  private static function sleepBeforeRetry(int $seconds): void {
      usleep(max(1, min(30, $seconds)) * 1000000);
  }
  ```
  Если провайдер вернул `Retry-After: 60`, мы ждём 30 секунд и сразу ретраимся → попадаем во второй 429. Cap должен быть выше для случаев когда сервер явно сказал «подожди дольше». Минимум — поднять cap до 120 или различать «провайдер указал» vs «наш backoff».

- 🟡 **#12. Empty API key fallback** ([LlmGateway.php:195-197](../planrun-backend/services/LlmGateway.php#L195-L197))
  ```php
  if ($apiKeyPool === []) { $apiKeyPool = ['']; }
  ```
  Если ни одного ключа не настроено — отправляем без Authorization. Большинство DeepSeek endpoints отдадут 401. Лучше fail-fast здесь с понятной ошибкой («No LLM API keys configured»), чем дожидаться 401 в проде.

- 🟡 **#13. `envInt` несогласована с `optionInt`** ([LlmGateway.php:808-813](../planrun-backend/services/LlmGateway.php#L808-L813))
  ```php
  $value = function_exists('env') ? env($key, $default) : getenv($key);
  ```
  `getenv($key)` возвращает `false` при отсутствии переменной, не `$default`. Поэтому если фреймворковая `env()` не определена и переменной нет — `$default` игнорируется. Маловероятно (env() есть в проекте), но это латентный баг.

- 🟢 **#14. `random_int` для API-key rotation overkill** (L78, L154) — CSPRNG не нужен для round-robin. `mt_rand` достаточно. Микро-оптимизация.

- 🟢 **#15. `LIMITER_TABLE` хардкод** (L55) — название схемы в строке через конкатенацию. Безопасно (не user input), но `prepare` + IDs было бы аккуратнее.

---

### `ChatActionParser.php` (206 строк)

**Назначение**: Sanitization выхода LLM (срезает reasoning leaks, английские preamble, emoji, legacy ACTION-блоки) + переводит проскользнувшие английские термины.

**Ключевые методы**:
- `sanitizeResponse` (L40) — главный pipeline очистки.
- `parseAndExecuteActions` (L98) — раньше парсил inline-actions, сейчас только удаляет legacy блоки. Имя метода **больше не отражает поведение**.
- `replaceEnglishTerms` (L118) — словарь из ~75 пар en→ru + дни/месяцы.
- `stripEmoji` (L182) — 11 unicode-диапазонов emoji.

**Найденные проблемы**:

- 🔴 **#16. Удаление местоимений ломает смысл** ([ChatActionParser.php:172-173](../planrun-backend/services/ChatActionParser.php#L172-L173))
  ```php
  $text = preg_replace('/\bthy\b/iu', '', $text);
  $text = preg_replace('/\b(your|my|his|her|its|our|their)\b/iu', '', $text);
  ```
  Это попытка убрать «леаки» английских местоимений, но **режет любое legitimate использование**: tool output типа «your VDOT is 52» — если такая фраза пройдёт через sanitizer, останется «VDOT is 52» с потерянным контекстом. Применяется к финальному ответу AI, а не к промпту — но если AI всё же ответил с английским словом — лучше показать его пользователю «как есть» и логгировать, чем тихо удалить.
  **Фикс**: убрать удаление местоимений, оставить только log+warning через `logLeakedEnglish` (которая уже есть на L200).

- 🟡 **#17. Performance: ~225 preg_replace на каждый ответ** — словарь L119-157 содержит ~75 терминов, для каждого делается 3 regex (L159, L161, L163). Плюс дни/месяцы — итого ~225 вызовов на ответ длиной 1-2КБ. На длинных стримах это заметно (50-100мс).
  **Фикс**: объединить термины в один alternation regex с callback-заменой, или применять только если в тексте найден `[a-zA-Z]{3,}` (т.е. вообще есть английские слова).

- 🟡 **#18. Risky Cyrillic prefix-cut** ([ChatActionParser.php:67-80](../planrun-backend/services/ChatActionParser.php#L67-L80))
  Логика «срезать английский preamble до первой кириллицы, если он 20-150 символов» — может отрезать legitimate английские аббревиатуры в начале (VDOT, HR, ATL/CTL). Условие `preg_match('/^[\s\p{P}A-Za-z0-9]+$/u')` пропускает «VDOT 52, your plan...» — там есть `,` и цифры, сработает.

- 🟢 **#19. Dead constant** — `ACTION_TOOLS` (L19) используется только в `stripAllActionBlocks` (L111) для сборки regex. Сам список ACTION-блоков **больше не парсится** (см. комментарий L96). Можно упростить до hardcoded регекспа или удалить, если ACTIONы давно не эмитятся.

- 🟢 **#20. `stripEmoji` 11 регэкспов подряд** — объединить в `preg_replace_callback` с одной маской.

---

### `ChatMemoryManager.php` (236 строк)

**Назначение**: Долговременная память пользователя в `chat_user_memory`. Извлекает факты из последних 20 сообщений через LLM, дедуплицирует, мержит, обрезает при превышении 2000 символов.

**Ключевые методы**:
- `extractAndSaveMemory` (L32) — главная entrypoint, вызывается из `ChatService::triggerMemoryExtraction`.
- `extractFacts` (L47) — LLM-вызов с system prompt о категориях фактов.
- `mergeFacts` (L136) — дедуп по 60% сходству слов.
- `compressMemory` (L185) — FIFO drop при overflow.
- `addFact` (L223) — программный путь без LLM (для tools/событий).

**Найденные проблемы**:

- 🔴 **#21. Race condition при concurrent extraction** ([ChatMemoryManager.php:205-217](../planrun-backend/services/ChatMemoryManager.php#L205-L217))
  ```php
  // getMemory → mergeFacts → saveMemory без транзакции
  ```
  Если два параллельных AI-стрима завершаются одновременно (stream + admin-pushed AI message), оба вызовут `triggerMemoryExtraction`. Каждый: читает старую память → мержит свои факты → пишет `ON DUPLICATE KEY UPDATE` целиком. Последний победит, факты первого потеряются.
  **Фикс**: либо `BEGIN; SELECT FOR UPDATE; UPDATE; COMMIT`, либо single UPDATE с `CONCAT()` на стороне БД, либо очередь экстракции (только одна задача per user).

- 🟡 **#22. FIFO compression может потерять травмы** ([ChatMemoryManager.php:185-191](../planrun-backend/services/ChatMemoryManager.php#L185-L191))
  ```php
  while (... && count($lines) > 5) { array_shift($lines); }
  ```
  Старые факты ценнее новых для контекста (хронические травмы, цели на год). Лучшая стратегия — приоритеты по категории (`[ТРАВМЫ]`, `[ЦЕЛИ]` сохранять; `[ПРИВЫЧКИ]`/`[РЕАКЦИИ]` drop первыми).

- 🟡 **#23. Stop-words включают спортивные термины** ([ChatMemoryManager.php:168](../planrun-backend/services/ChatMemoryManager.php#L168))
  ```php
  $stopWords = [..., 'бег', 'км', 'мин'];
  ```
  Для дедупа `isSimilarFact` исключает эти слова. Это значит «лёгкий бег 5 км» и «лёгкий бег 10 км» — после удаления `бег`/`км` будут считаться одинаковыми по 60% threshold. Потенциальная утрата.

- 🟡 **#24. False positive «ПУСТО»** ([ChatMemoryManager.php:115](../planrun-backend/services/ChatMemoryManager.php#L115))
  ```php
  if (mb_stripos($content, 'ПУСТО') !== false || mb_stripos($content, 'пусто') !== false)
  ```
  Если LLM ответил «[ПРИВЫЧКИ] У пользователя пустой холодильник по утрам» — слово «пусто» в факте сделает return early. Используй точное `trim($content) === '' || trim($content) === 'ПУСТО'`.

- 🟢 **#25. `addFact` не atomic** (L223) — те же гонки что и в #21.

---

### `ChatConfirmationHandler.php` (448 строк)

**Назначение**: распознать «да/ок/давай» как подтверждение последнего предложения AI, распарсить предложение и выполнить tool через `ChatToolRegistry`.

**Ключевые методы**:
- `isConfirmationMessage` (L22) — короткие однословные подтверждения.
- `tryHandleSwapConfirmation` (L32) — swap-специфичный handler.
- `tryHandleReplaceWithRaceConfirmation` (L56) — замена двух дней на race + recovery.
- `tryHandleGenericUpdateConfirmation` (L97) — обобщённый: пробует 8 разных try* методов.
- `parseGenericUpdateProposal` (L367), `extractDescriptionFromProposal` (L400), `parseReplaceWithRaceProposal` (L416) — regex-парсеры предложений AI.

**Найденные проблемы**:

- 🔴 **#26. `tryExtractFromLastProposal` ищет несуществующий sender_type** ([ChatConfirmationHandler.php:118 vs L156](../planrun-backend/services/ChatConfirmationHandler.php#L118))
  ```php
  // L118 (внутри tryExtractFromLastProposal):
  if (($history[$i]['sender_type'] ?? '') === 'assistant') { ... }

  // L156 (внутри getLastAssistantMessage, тот же класс):
  if (($history[$i]['sender_type'] ?? '') === 'ai') { ... }
  ```
  В БД `chat_messages.sender_type` хранит **`'ai'`** (не `'assistant'`). `getLastAssistantMessage` — правильно, `tryExtractFromLastProposal` — **никогда не найдёт сообщение**, всегда вернёт `null`. Метод `public`, может вызываться извне.
  **Фикс**: заменить `'assistant'` → `'ai'` на L118. Параллельно проверить, где вызывается этот метод и работает ли соответствующая фича.

- 🟡 **#27. Хрупкие regex-парсеры предложений** — например `parseReplaceWithRaceProposal` L416-447 содержит сложные регулярки на конкретные русские формулировки. Любое отклонение в формате ответа AI (запятая, тире, эмодзи) ломает confirmation flow и пользователь остаётся без действия.
  **Системное**: подтверждения в текстовом виде — антипаттерн в эпоху native tool-calling. Альтернатива — модель эмитит «pending_tool_call» в сообщении через специальный tool `propose_action(...)`, а handler вытаскивает структурированные args. Этот рефакторинг закроет почти всё в этом файле.

- 🟢 **#28. Дублирование `toolsUsed[] = 'update_training_day'`** ([ChatConfirmationHandler.php:84-85](../planrun-backend/services/ChatConfirmationHandler.php#L84-L85)) — намеренно (два разных update'a), но через `addToolCallToMessages` было бы аккуратнее.

- 🟢 **#29. `extractReplaceDatesFromText` — просто alias `extractSwapDatesFromText`** (L203-205). Дублирование.

- 🟢 **#30. Tight coupling на error string** ([ChatConfirmationHandler.php:283](../planrun-backend/services/ChatConfirmationHandler.php#L283)) — `str_contains($result['error'] ?? '', 'not_found')` — если ToolRegistry поменяет код ошибки, фолбэк сломётся молча. Использовать машиночитаемые коды (`'no_plan_for_date'`).

---

### `ChatPromptBuilder.php` (535 строк)

**Назначение**: Сборка `messages` для LLM. Управляет бюджетом токенов (32K cap), нормализует чередование ролей, добавляет даты в конце user-сообщения (для DeepSeek prefix cache).

**Ключевые методы**:
- `buildChatMessages` (L56) — главный entry.
- `buildCompressedSystemPrompt` (L118) — статичная часть промпта (cache-friendly).
- `buildDatesSuffix` (L163) — переменная часть, в конце user-msg.
- `normalizeMessagesForStrictAlternation` (L259) — стыковка кривой истории под требование DeepSeek.
- `appendChatSearchSnippet` (L333) — поиск по chat history.
- `appendRagSnippet` (L376) — запрос к PlanRun AI /retrieve-knowledge.
- `hasReplaceWithRaceIntent` / `hasAddTrainingIntent` — keyword-based intent detection для динамических addons промпта.

**Хорошо**:
- L83-86: явный комментарий про DeepSeek prefix cache + URL на доки. Дисциплина по cache hit rate видна.
- `normalizeMessagesForStrictAlternation` — устойчиво к проактивным AI-сообщениям (которые приходят без preceding user message).

**Найденные проблемы**:

- 🟡 **#31. Verb «запиши» triggers add-training, но это log_workout** ([ChatPromptBuilder.php:446](../planrun-backend/services/ChatPromptBuilder.php#L446))
  ```php
  $verbs = [..., 'запиши', 'записать', ...];
  ```
  «запиши тренировку» — добавить в план, ОК. «запиши: пробежал 10км» — это `log_workout`. Текущая логика добавит add-training addon к промпту, что может сбить модель.
  **Фикс**: либо разделить intent-детекторы, либо добавить exclude phrases типа «пробежал», «выполнил», «было».

- 🟡 **#32. RAG snippet без кэша добавляет latency** ([ChatPromptBuilder.php:386-394](../planrun-backend/services/ChatPromptBuilder.php#L386-L394))
  Каждый стрим делает HTTP до PlanRun AI с таймаутом 15с. Если RAG ответит за 8с — это +8с до первого токена. На запросы, где RAG-выдача стабильна (общие термины бега), стоит кэшировать query→sources на 1-24ч.

- 🟡 **#33. RAG URL transform та же проблема что в ChatService** ([ChatPromptBuilder.php:383](../planrun-backend/services/ChatPromptBuilder.php#L383)) — `preg_replace('#/generate-plan$#', '/retrieve-knowledge', $base)`.

- 🟢 **#34. `MAX_CONTEXT_TOKENS = 32000`** — консервативно для DeepSeek (128K). Можно поднять до 64-96K для better-recall (особенно plan-history-analyses + memory + chat-search + RAG могут не вместиться).

- 🟢 **#35. CHARS_PER_TOKEN = 3.2 эмпирика** — для tool outputs с JSON это переоценено (JSON более «токеноёмкий»). Может вызывать ложный triггер trim.

- 🟢 **#36. Дублирование daysRu** (L67, L120, L165, L529) — массив `пн..вс` определяется 4 раза.

---

### `ChatToolRegistry.php` (1079 строк)

**Назначение**: Единый файл объявления tools (`getChatTools` L21) и их исполнения (`executeTool` L114). 25 tools для read-only данных + write-операций + расчёта VDOT.

**Хорошо**:
- L209-225: `resolveNaturalDateArgs` — авто-резолв «завтра»/«в среду» в Y-m-d перед вызовом любого tool. Защита от ленивой модели.
- L443-466 (`executeMoveTrainingDay`): «delete-then-copy + rest» вместо delete+copy с дырой в календаре. Inline-комментарий объясняет почему — **отличная документация рядом с кодом**.
- L378-389 (`executeGetDayDetails`): обработка multi-workout дня с подсказкой модели "Расскажи обо ВСЕХ".
- L820-833 (`loadTrainingState`): lazy + per-request кэш для heavy state. Хорошо.
- L114-122 (`executeTool`): graceful обработка некорректного JSON в args — пишет в лог, продолжает с пустыми args.

**Найденные проблемы**:

- 🟡 **#37. 25 tools и dispatch-array на каждый вызов** ([ChatToolRegistry.php:129-155](../planrun-backend/services/ChatToolRegistry.php#L129-L155))
  ```php
  $dispatch = [
      'get_date' => fn() => $this->executeGetDate($args, $userId),
      ...
  ];
  ```
  Создаёт 25 closures на каждый `executeTool` вызов. На реальном tool loop с 3-5 раундами по 1-3 tools = 75+ closures впустую. Лучше — `match($name) { ... }` или явный switch.

- 🟡 **#38. VDOT magic numbers без источника** ([ChatToolRegistry.php:782-787, 800-806](../planrun-backend/services/ChatToolRegistry.php#L782-L787))
  Числа `0.000104`, `0.182258`, `-4.60`, `0.97`/`0.93`/`0.85`/`0.79` — это формула Daniels' VDOT. Нужен комментарий с источником (Jack Daniels, "Daniels' Running Formula"). Иначе через год никто не поймёт, что менять можно и что нельзя.

- 🟡 **#39. `requireUser` returns string error** ([ChatToolRegistry.php:186-188](../planrun-backend/services/ChatToolRegistry.php#L186-L188))
  ```php
  return $userId ? null : json_encode(['error' => 'user_required']);
  ```
  Использование: `if ($err = $this->requireUser($userId)) return $err;`. Возврат строки vs `null` работает, но менее типобезопасно. Должно бы быть throws или сразу `RuntimeException`.

- 🟡 **#40. `loadTrainingState` cache pollution** ([ChatToolRegistry.php:822](../planrun-backend/services/ChatToolRegistry.php#L822))
  ```php
  private array $stateCache = [];
  ```
  Кэш по `$userId` живёт всю жизнь объекта `ChatToolRegistry`. В рамках одного HTTP-запроса OK. Но если объект переиспользуется (например, в воркере) — устаревший state на нескольких пользователей. В контексте FPM не критично.

- 🟢 **#41. `executeGetWeather` лимит 6 дней хардкод** (L1018).

- 🟢 **#42. Дублирование SQL** — паттерн `LOWER(COALESCE(NULLIF(TRIM(activity_type), ''), 'running')) COLLATE utf8mb4_unicode_ci` повторяется 4+ раз. Вынести в SQL view или helper.

- 🟢 **#43. `executeGetStats` для period=plan не использует фильтр** (L592-594) — `$filtered = $dateFrom ? ... : $all;` — для `'plan'` или `'all'` возвращает все 500. Для очень долгой истории — много памяти.

---

### `ChatContextBuilder.php` (1290 строк)

**Назначение**: Сбор полного контекста пользователя в plain-text. Самый крупный файл фазы. Включает: профиль, цели, план, статистику, coaching-инсайты (ACWR, compliance, plan-vs-actual), последние тренировки, wellness, plan-history-analyses, память, summary истории чата.

**Ключевые методы**:
- `buildContextForUser` (L60) — главный.
- `formatProfile` (L145), `formatPlanSummary` (L260), `formatStats` (L364), `formatRecentActivity` (L451), `formatRecentWellness` (L487), `formatCoachingInsights` (L638), `formatPlanHistoryAnalyses` (L23), `formatLatestPlanGeneratorSummary` (L386) — секционные форматтеры.
- `calculateACWR` (L760) — расчёт Acute:Chronic Workload Ratio через sRPE.
- `getWeeklyCompliance` (L841), `getLoadTrend` (L899), `getThisWeekPlanVsActual` (L1013) — coaching сигналы.
- `getDayDetails` (L1117), `getWorkoutsHistory` (L1216), `getRecentWorkouts` (L553) — общие data-getters, используемые ToolRegistry.

**Хорошо**:
- ACWR расчёт через sRPE (правильная sports science) с фолбэком на distance как proxy.
- Многосекционный prompt с заголовками `═══ СЕКЦИЯ ═══` (унифицировано).
- Wellness-блок (L487-521) аккуратно различает сегодняшние записи: «зафиксировано ранее в HH:MM» — модель видит свежесть и может предложить обновление.
- L74-78: память помечена «МОЖЕТ БЫТЬ УСТАРЕВШЕЙ» с прямой инструкцией модели сверяться с текущим планом — хорошее prompt engineering.

**Найденные проблемы**:

- 🟡 **#44. `CURDATE()` без TZ user'a** ([ChatContextBuilder.php:494](../planrun-backend/services/ChatContextBuilder.php#L494))
  ```sql
  WHERE user_id = ? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
  ```
  Если сервер в Europe/Moscow, а пользователь во Владивостоке — в его 7:00 утра МСК ещё вчера. Записи могут «дребезжать» через границу полуночи. Использовать TZ пользователя.

- 🟡 **#45. Хитрый UNION с derived table из 15 чисел** ([ChatContextBuilder.php:530-535](../planrun-backend/services/ChatContextBuilder.php#L530-L535))
  ```sql
  SELECT DATE_SUB(?, INTERVAL n.n DAY) AS d
  FROM (SELECT 0 n UNION SELECT 1 UNION ... UNION SELECT 14) n
  ```
  Хитро, но непрозрачно. Заменить на recursive CTE или сгенерировать массив в PHP и сделать `WHERE date IN (?)`.

- 🟡 **#46. Дублирование SQL** — `getRecentWorkouts` (L553-632), `getWorkoutsHistory` (L1216-1289) почти идентичны (отличаются параметром лимита и фильтром по дате). Вынести в общий helper.

- 🟡 **#47. ACWR fallback proxy = `distance × 6`** ([ChatContextBuilder.php:804-805](../planrun-backend/services/ChatContextBuilder.php#L804-L805))
  ```php
  // Proxy: дистанция × 6 (среднее ~6 мин/км)
  $load = $dist * 6;
  ```
  Это эквивалентно «duration_min × 1.0» (как очень тяжёлая). Для лёгкой 10км пробежки получится load = 60 (как тяжёлая 1ч интервалов с rating=1). Завышает acute load для атлетов, которые не ставят RPE. Лучше — `dist * 6 * 0.5` или явно занижать intensity factor при отсутствии rating.

- 🟢 **#48. `getUserTz` дублируется в 4+ местах** проекта (ChatContextBuilder, ChatToolRegistry, ChatConfirmationHandler) — вынести в helper `user_functions.php`.

- 🟢 **#49. `formatPlanSummary` находит «текущую» неделю циклом** (L278-306) — для плана из 16 недель = 16 итераций. OK на масштабах, но `WHERE date BETWEEN` в SQL быстрее.

- 🟢 **#50. `last write wins` при дубликатах плана на дату** ([ChatContextBuilder.php:1031](../planrun-backend/services/ChatContextBuilder.php#L1031)) — в `$planned[$row['date']] = $row['type']`. ToolRegistry уже использует `ORDER BY id DESC LIMIT 1` для устойчивости — тут стоит сделать так же.

- 🟢 **#51. `formatRecentActivity` использует ASCII emoji `⚠`** (L477, L670, L673) — а ChatActionParser потом стрипает эмодзи (#20). Может пропускаться или удаляться непоследовательно. `⚠` (U+26A0) попадает в диапазон `\x{2600}-\x{27BF}` → удаляется. **Контекст для модели НЕ виден** из-за этого. Используйте текстовые маркеры (`[!]`, `ВНИМАНИЕ:`) в context, а не в выходе модели.

---

## Phase 1 — Сводная таблица проблем

| # | Файл:строка | Тяжесть | Категория | Краткое описание |
|---|---|---|---|---|
| 1 | ChatService.php:237 | 🔴 | bug | `$db->insert_id` после `addMessage` может вернуть неверный ID |
| 2 | ChatService.php:202/375 | 🔴 | bug | Memory extraction и confirmation handlers только в stream-пути |
| 11 | LlmGateway.php:797 | 🔴 | bug | `sleepBeforeRetry` cap 30s игнорирует длинный Retry-After |
| 16 | ChatActionParser.php:172 | 🔴 | quality | Удаление всех en-местоимений ломает смысл legitimate использования |
| 21 | ChatMemoryManager.php:205 | 🔴 | race | Конкурентные `extractAndSaveMemory` теряют факты |
| 26 | ChatConfirmationHandler.php:118 | 🔴 | bug | `tryExtractFromLastProposal` ищет `'assistant'`, но DB хранит `'ai'` — всегда возвращает null |
| 3 | ChatService.php:468/491 | 🟡 | smell | Использование переменной до объявления |
| 4 | ChatService.php:285 | 🟡 | perf | Health check на каждый stream запрос |
| 5 | ChatService.php:541/707 | 🟡 | fragile | URL transform `preg_replace('/generate-plan$/', '/chat')` |
| 6 | ChatService.php:321 | 🟡 | perf/safety | Think-tag buffer без верхней границы |
| 7 | ChatService.php:298 | 🟡 | style | `array_intersect` как bool |
| 12 | LlmGateway.php:195 | 🟡 | safety | Empty API-key fallback — fail-late вместо fail-fast |
| 13 | LlmGateway.php:808 | 🟡 | bug | `getenv()` vs `env()` несогласованность default |
| 17 | ChatActionParser.php:118 | 🟡 | perf | ~225 preg_replace на ответ |
| 18 | ChatActionParser.php:67 | 🟡 | quality | Рискованный cut-английского-preamble может отрезать valid Latin |
| 22 | ChatMemoryManager.php:185 | 🟡 | quality | FIFO compression теряет важные старые факты (травмы) |
| 23 | ChatMemoryManager.php:168 | 🟡 | quality | Stop-words включают спортивные термины |
| 24 | ChatMemoryManager.php:115 | 🟡 | bug | False positive «ПУСТО» внутри факта |
| 27 | ChatConfirmationHandler.php:* | 🟡 | design | Хрупкие regex-парсеры предложений |
| 30 | ChatConfirmationHandler.php:283 | 🟡 | coupling | Тайт-coupling на текст error message |
| 31 | ChatPromptBuilder.php:446 | 🟡 | quality | «запиши» triggers add-training, но это log_workout |
| 32 | ChatPromptBuilder.php:386 | 🟡 | perf | RAG snippet без кэша добавляет 5-15с latency |
| 37 | ChatToolRegistry.php:129 | 🟡 | perf | Dispatch-array из 25 closures на каждый вызов |
| 38 | ChatToolRegistry.php:782 | 🟡 | docs | VDOT magic numbers без источника |
| 40 | ChatToolRegistry.php:822 | 🟡 | safety | `stateCache` риск при долгоживущем объекте |
| 44 | ChatContextBuilder.php:494 | 🟡 | bug | `CURDATE()` без TZ user'a |
| 45 | ChatContextBuilder.php:530 | 🟡 | maintenance | Хитрый UNION 15 чисел вместо CTE |
| 46 | ChatContextBuilder.php:553/1216 | 🟡 | DRY | Дублирование больших SQL запросов |
| 47 | ChatContextBuilder.php:804 | 🟡 | math | ACWR proxy `dist×6` завышает acute load |
| 51 | ChatContextBuilder.php:* | 🟡 | bug | `⚠` в context стрипается ChatActionParser → ситуационные предупреждения теряются |
| 8 | ChatService.php:297 | 🟢 | style | Литерал-массив внутри метода — в const |
| 9 | ChatService.php:888/897 | 🟢 | style | `prepare` vs `query` несогласованность |
| 10 | ChatService.php:864 | 🟢 | DRY | Дублирование truncate-логики |
| 14 | LlmGateway.php:78 | 🟢 | perf | `random_int` overkill для key rotation |
| 15 | LlmGateway.php:55 | 🟢 | style | Хардкод имени таблицы через конкатенацию |
| 19 | ChatActionParser.php:19 | 🟢 | cleanup | `ACTION_TOOLS` constant — потенциально dead |
| 20 | ChatActionParser.php:182 | 🟢 | perf | 11 regex для emoji — объединить |
| 25 | ChatMemoryManager.php:223 | 🟢 | race | `addFact` не atomic |
| 28 | ChatConfirmationHandler.php:84 | 🟢 | style | Дублирование `$toolsUsed[]` |
| 29 | ChatConfirmationHandler.php:203 | 🟢 | DRY | `extractReplaceDatesFromText` — alias |
| 33 | ChatPromptBuilder.php:383 | 🟢 | fragile | Та же проблема с URL transform |
| 34 | ChatPromptBuilder.php:14 | 🟢 | tuning | `MAX_CONTEXT_TOKENS=32000` консервативно |
| 35 | ChatPromptBuilder.php:18 | 🟢 | tuning | CHARS_PER_TOKEN неточно для JSON |
| 36 | ChatPromptBuilder.php:67 | 🟢 | DRY | `daysRu` массив определяется 4 раза |
| 41 | ChatToolRegistry.php:1018 | 🟢 | tuning | Жёсткий лимит 6 дней погоды |
| 42 | ChatToolRegistry.php:* | 🟢 | DRY | Activity_type COLLATE дублируется в SQL |
| 43 | ChatToolRegistry.php:592 | 🟢 | perf | `period=plan` грузит 500 тренировок |
| 48 | ChatContextBuilder.php:314 | 🟢 | DRY | `getUserTz` дублируется в 4+ местах |
| 49 | ChatContextBuilder.php:278 | 🟢 | perf | Цикл по неделям вместо SQL WHERE BETWEEN |
| 50 | ChatContextBuilder.php:1031 | 🟢 | bug | `last write wins` при дубликатах плана |

**Итого 51 найденная проблема** в Phase 1: 6 критичных, 26 средних, 19 мелочей.

### Главные рекомендации по Phase 1

1. **Срочно**: исправить `message_id` (#1), `tryExtractFromLastProposal` 'ai' vs 'assistant' (#26), вынести pre/post-обработку чата в общий helper для stream и non-stream (#2).
2. **Архитектурно**: убрать regex-based confirmation handlers (#27), заменив на `propose_action(...)` tool, который AI вызывает заранее. Это закроет ~448 строк хрупкого кода.
3. **Performance**: кэшировать health-check (#4), RAG (#32), оптимизировать словарь en→ru (#17), `loadTrainingState` уже OK.
4. **Концурентность**: для memory extraction внедрить либо row lock, либо очередь (одна задача per user) (#21, #25).
5. **DRY** в SQL — выделить shared `WorkoutQueryBuilder` для трёх запросов в ChatContextBuilder/ToolRegistry (#46, #42).

---

## Phase 2 — Plan generation

### Файлы фазы

**Новый pipeline (services/):**

| Файл | Строк | Роль |
|---|---|---|
| [`PlanGenerationProcessorService.php`](../planrun-backend/services/PlanGenerationProcessorService.php) | 2318 | Главный оркестратор очереди генерации |
| [`PlanQualityGate.php`](../planrun-backend/services/PlanQualityGate.php) | 787 | Финальная проверка перед сохранением |
| [`PlanSkeletonBuilder.php`](../planrun-backend/services/PlanSkeletonBuilder.php) | 677 | Сборка skeleton (не используется в llm_planner-пути) |
| [`PlanReadinessCheckService.php`](../planrun-backend/services/PlanReadinessCheckService.php) | 487 | Stale pain signal check перед recalculate |
| [`PlanGenerationQueueService.php`](../planrun-backend/services/PlanGenerationQueueService.php) | 332 | Очередь задач |
| [`PlanScenarioResolver.php`](../planrun-backend/services/PlanScenarioResolver.php) | 318 | Detection сценария (B-race, taper, return-after-injury) |
| [`PlanExplanationService.php`](../planrun-backend/services/PlanExplanationService.php) | 292 | Человеко-читаемый summary |
| [`PlanNotificationService.php`](../planrun-backend/services/PlanNotificationService.php) | 213 | Уведомления о готовности плана |

**Legacy (planrun_ai/):**

| Файл | Строк | Роль |
|---|---|---|
| [`prompt_builder.php`](../planrun-backend/planrun_ai/prompt_builder.php) | 3538 | VDOT, macrocycle, build* helpers и весь prompt-engineering |
| [`plan_normalizer.php`](../planrun-backend/planrun_ai/plan_normalizer.php) | 1791 | Нормализация плана + load/pace/distance repairs |
| [`llm_planner/DeepSeekPlanPlanner.php`](../planrun-backend/planrun_ai/llm_planner/DeepSeekPlanPlanner.php) | 1302 | Новый DeepSeek client (production path) |
| [`plan_generator.php`](../planrun-backend/planrun_ai/plan_generator.php) | 1268 | Legacy entry (generatePlanViaPlanRunAI/recalculate/next) |
| [`plan_saver.php`](../planrun-backend/planrun_ai/plan_saver.php) | 495 | DB-writes (saveTrainingPlan, saveRecalculatedPlan) |
| [`plan_critique_generator.php`](../planrun-backend/planrun_ai/plan_critique_generator.php) | 492 | Self-critique LLM pass |
| [`plan_review_generator.php`](../planrun-backend/planrun_ai/plan_review_generator.php) | 483 | Plan review для чата |
| [`ofp_enricher.php`](../planrun-backend/planrun_ai/ofp_enricher.php) | 273 | ОФП/СБУ enrichment через LLM |
| [`generate_plan_async.php`](../planrun-backend/planrun_ai/generate_plan_async.php) | 163 | Legacy CLI entry (systemctl worker) |
| [`planrun_ai_integration.php`](../planrun-backend/planrun_ai/planrun_ai_integration.php) | 162 | callAIAPI helper |
| [`description_parser.php`](../planrun-backend/planrun_ai/description_parser.php) | 116 | Парсер ОФП/СБУ description |
| [`text_generator.php`](../planrun-backend/planrun_ai/text_generator.php) | 94 | Текстовые описания (legacy fallback) |
| [`create_empty_plan.php`](../planrun-backend/planrun_ai/create_empty_plan.php) | 88 | «Самостоятельный режим» |
| [`plan_validator.php`](../planrun-backend/planrun_ai/plan_validator.php) | 74 | Routing valid-pipeline (validators/*) |
| [`planrun_ai_config.php`](../planrun-backend/planrun_ai/planrun_ai_config.php) | 38 | Constants/env-loader |

**Validators (planrun_ai/validators/):**

| Файл | Строк | Роль |
|---|---|---|
| `pace_validator.php` | 237 | easy/long/tempo/interval/fartlek pace bounds |
| `workout_completeness_validator.php` | 155 | tempo/control/interval/fartlek structure |
| `load_validator.php` | 122 | weekly volume spike + back-to-back key workouts |
| `goal_consistency_validator.php` | 113 | health-goal too many quality, special-pop guards |
| `schedule_validator.php` | 85 | preferred_days + skeleton mismatch |
| `taper_validator.php` | 76 | race-week supplementary volume + taper reduction |

_Итого: ~14 000 строк._

### Архитектура потока

**Production (PLAN_GENERATION_MODE=llm_planner)**:

```
PlanGenerationQueueService::reserveNextJob (SELECT ... FOR UPDATE SKIP LOCKED)
   └─ PlanGenerationProcessorService::process(userId, jobType, payload, jobId)
        ├─ AiObservabilityService::createTraceId
        ├─ processViaLlmPlanner
        │     ├─ enrichRecalculatePayload / enrichNextPlanPayload
        │     │     (cutoff_date, kept_weeks, actual_weekly_km_4w, progression_counters)
        │     ├─ DeepSeekPlanPlanner::generate
        │     │     ├─ TrainingStateBuilder::buildForUser → VDOT, paces, special_flags
        │     │     ├─ PlanScenarioResolver::resolve → flags + tune_up
        │     │     ├─ resolveModelSelection → deepseek-chat vs deepseek-reasoner
        │     │     ├─ buildPlannerContext → FACTS_JSON (training_state + hard_rules + calendar_weeks)
        │     │     ├─ buildFullPlanPrompt + system prompt («тренер, диагноз → стратегия → календарь»)
        │     │     ├─ LlmGateway::requestChatCompletion (response_format=json_object)
        │     │     ├─ alignWeekTargetsToCalendar
        │     │     └─ deriveMacroPlanFromWeeks
        │     ├─ enforceRaceDayConsistency → placeRaceOnCalendarDate + capRaceWeekSupplementaryVolume
        │     ├─ ensureIntermediateRacesInPlan (safety-net для забегов)
        │     ├─ applySinglePassHardSafetyRepairs (medical: marathon long >32km @ <21d → cap)
        │     ├─ PlanQualityGate::evaluate
        │     │     ├─ normalizeTrainingPlan
        │     │     ├─ applyDeterministicRepairs (pace, fallbacks, load × 2)
        │     │     ├─ collectScenarioIssues (tune-up event, b-race)
        │     │     ├─ collectLlmPlannerContractIssues (language, macro/detail mismatch, long-run safety)
        │     │     ├─ collectGoalFeasibilityIssues
        │     │     ├─ downgradeProtectiveScenarioIssues (smooth для conservative cohorts)
        │     │     ├─ filterIssuesForScenario (relax `missing_run_on_required_day`)
        │     │     ├─ applyBlockingPolicy (strict|permissive)
        │     │     └─ если has_errors → throw, save заблокирован
        │     └─ build _generation_metadata.quality_gate / hard_safety_repairs
        ├─ applyPlanCritique (runPlanSelfCritique + revisePlanWithCritique)
        ├─ ensureIntermediateRacesInPlan (повторно после critique)
        ├─ enrichPlanWithOfpAndSbu (отдельный LLM-вызов на ОФП)
        ├─ ensureOfpDaysInPlan (template fallback)
        ├─ attachGenerationExplanation (PlanExplanationService)
        ├─ saveTrainingPlan / saveRecalculatedPlan (с skipNormalization=true)
        ├─ syncLatestTrainingPlanSnapshot
        ├─ appendPlanReview (plan_review_generator → chat message)
        ├─ persistPlanSummary
        └─ AiPlanGenerationEventLogger::recordSuccess
```

**Legacy fallback (PLAN_GENERATION_MODE='')**: те же 3 пути, но через `generatePlanViaPlanRunAI` → `prompt_builder.php` → `callAIAPI` → `parseAndRepairPlanJSON` → `applyCritiquePassToPlanData` → normalize → save.

**Async worker entrypoint**: `generate_plan_async.php` запускается из systemctl сервиса `planrun-plan-generation-worker.service` (виден в корне репозитория). Делает то же что `process()`, но обходит queue layer.

### Найденные проблемы

#### `planrun_ai_integration.php`

- 🔴 **#52. `resolvePlanRunAIMaxTokens` default cap режет ВСЕ запросы** ([planrun_ai_integration.php:22-25](../planrun-backend/planrun_ai/planrun_ai_integration.php#L22-L25))
  ```php
  $hardLimit = max(512, (int) env('PLANRUN_AI_MAX_TOKENS_HARD_LIMIT', 4096));
  if ($tokens > $hardLimit) {
      error_log("resolvePlanRunAIMaxTokens: capped max_tokens {$tokens} -> {$hardLimit}");
  }
  return min($tokens, $hardLimit);
  ```
  Функция выбирает 12/16/20K токенов в зависимости от объёма плана, потом ВСЕГДА капает до 4096 (если env не задан). Это значит **планы по умолчанию обрезаются на 4K токенах**, что приводит к truncated JSON ответам. Видимо `PLANRUN_AI_MAX_TOKENS_HARD_LIMIT` в `.env` должен быть выше — но дефолт опасен. Поднять до 32768 или хотя бы 16384.

- 🔴 **#53. `$httpCode` undefined в catch блоке** ([planrun_ai_integration.php:126](../planrun-backend/planrun_ai/planrun_ai_integration.php#L126))
  ```php
  $isRetryable = strpos($errorMessage, 'timeout') !== false ||
                strpos($errorMessage, 'Connection') !== false ||
                ($httpCode >= 500 && $httpCode < 600);
  ```
  `$httpCode` объявлена в `try` (L92), но если cURL упал до `curl_exec` или throw до её присвоения — `$httpCode` undefined → PHP warning + truthy `(0 >= 500)` = false. Retry-логика на 5xx **не работает на самом первом attempt**.

- 🟡 **#54. SSL verification disabled** ([planrun_ai_integration.php:87-88](../planrun-backend/planrun_ai/planrun_ai_integration.php#L87-L88))
  `CURLOPT_SSL_VERIFYPEER => false` — комментарий «локальный сервер», но если кто-то задаст `PLANRUN_AI_API_URL` с https-эндпойнтом — MITM-уязвимость.

- 🟡 **#55. Linear backoff без jitter** (L57) — `$retryDelay *= 2` без random jitter; при массовом сбое все ретраи одновременно.

#### `generate_plan_async.php`

- 🟢 **#56. Legacy CLI entry** — дублирует логику `PlanGenerationProcessorService::process`. После Phase D.3 (`USE_SKELETON_GENERATOR` удалён) этот скрипт остался как **редкий direct-CLI путь**. Если worker сервис вызывает только `process()` через очередь — этот файл dead code. Если используется — нет synchronization с PlanGenerationQueueService dedup.
- 🟡 **#57. Reason/goals tempfiles** (L21-29) — читает `--reason-file=` и unlink-ает после. Если процесс упадёт между read и unlink — файл (с пользовательским текстом) остаётся на диске.

#### `text_generator.php`

- 🟢 **#58. Dead path** — `generateTextFromExercises` парсит ответ ожидая `result['description']`, но `callPlanRunAIAPI` (L118 в integration) возвращает `json_encode($result['plan'])` — это совершенно другой контракт. Эта ветка никогда не работает по основному пути; всегда возвращается `generateSimpleDescription` fallback. Удалить LLM-блок.

#### Validators

- 🟡 **#59. `_paceCheckEasy` dead branch** ([pace_validator.php:67](../planrun-backend/planrun_ai/validators/pace_validator.php#L67))
  ```php
  $min = max(150, ...);
  ...
  if ($min === 0 || $max === 0 || ...) { return []; }
  ```
  После `max(150, ...)` `$min` никогда не 0. Условие confusing/dead. То же для `$max` (`min(600, ...)`).

- 🟡 **#60. Hardcoded windows** в `taper_validator.php` (L64: `0.98`), `goal_consistency_validator` (severity thresholds), `load_validator` (`+0.75`, `+0.04`) — десятки магических чисел без единого config.

#### `PlanGenerationQueueService`

- 🟡 **#61. `isSkipLockedCompatibilityError` хрупкая heuristic** ([PlanGenerationQueueService.php:326-330](../planrun-backend/services/PlanGenerationQueueService.php#L326-L330))
  Проверяет error message на подстроки `'skip locked'`/`'for update'`/`'syntax'`. Если MySQL изменит wording (или localized error) — fallback не сработает. Лучше проверять SQLSTATE/error code.

- 🟢 **#62. `assertQueueTableAvailable` overhead** — `SHOW TABLES LIKE` на каждый enqueue/reserve. Кешировать в статике.

#### `PlanReadinessCheckService`

- 🟡 **#63. `ensureSchema()` каждый раз** — CREATE TABLE IF NOT EXISTS на каждый `maybeCreatePendingCheck` (L50) и `getLatestValidAnswer` (L174) и `submitAnswer` (L111). MySQL это no-op, но всё равно лишний round-trip. Перенести в миграции.

- 🟢 **#64. Magic windows** — `2 DAY` для dismissal/pending (L325, L359), `21 DAY` lookback (L237), `10/5 DAY` validity (L130) — все хардкод.

#### `PlanSkeletonBuilder`

- 🟡 **#65. Cyclomatic complexity** — `resolveQualityTypes` (L428-598, 170 строк, 8+ branches), `resolveWeekRunDays` (L110-229) — сложно тестировать; малейшее изменение в одной ветке может незаметно сломать другую когорту. Разбить на стратегии.

- 🟢 **#66. `DEFAULT_RUN_DAY_ORDERS` хардкод** (L6) — для пользователей без `preferred_days`. Эти distributions (1→wed, 2→tue+sat, ...) — спортивная экспертиза, ОК как const.

#### `PlanQualityGate`

- 🔴 **#67. Дублированный вызов `applyTrainingStateLoadRepairs`** ([PlanQualityGate.php:78-80](../planrun-backend/services/PlanQualityGate.php#L78-L80))
  ```php
  $repaired = applyTrainingStatePaceRepairs($normalizedPlan, $trainingState);
  $repaired = applyTrainingStateWorkoutDetailFallbacks($repaired, $trainingState);
  $repaired = applyTrainingStateLoadRepairs($repaired, $trainingState);
  $repaired = applyTrainingStateMinimumDistanceRepairs($repaired, $trainingState);
  $repaired = applyTrainingStateLoadRepairs($repaired, $trainingState);  // ← дубль!
  ```
  Без комментария зачем. Если намеренно (минимум-distance repair мог развалить load balance) — нужен комментарий. Если случайно — лишний цикл + риск двойной модификации.

- 🟡 **#68. `containsForbiddenEnglishTrainingText` дублирует ChatActionParser** (L691-697) — два разных списка терминов в проекте.

- 🟡 **#69. Permissive mode может сохранить bad plan** (L767-786) — все error'ы кроме `invalid_week_day_count`/`schedule_skeleton_mismatch` downgrade'ятся до warning. Это значит `marathon_long_run_too_close_to_race` сохранится — а это медицинский риск. Список fatal-кодов должен быть шире.

#### `PlanGenerationProcessorService`

- 🟡 **#70. Dual-path после Phase D.3** — `process()` проверяет `PLAN_GENERATION_MODE`. Если `'llm_planner'` — современный путь, иначе legacy `generatePlanViaPlanRunAI`. Если в production используется только llm_planner — legacy ветка dead code (но 100+ строк её обвязки и зависимостей всё ещё компилятся).

- 🔴 **#71. Activity-type фильтрация после SQL вместо WHERE** ([PlanGenerationProcessorService.php:1104-1147](../planrun-backend/services/PlanGenerationProcessorService.php#L1104-L1147))
  `enrichRecalculatePayload` берёт ВСЕ workouts из workout_log/workouts за 4 недели, потом фильтрует в PHP через `isRunningRelevantManualActivity`. Для активного пользователя со Strava-импортом это сотни строк (вело, плавание, силовые) → плавание попадает в `actual_weekly_km_4w` фильтр-цикл, потом отбрасывается. Лучше `WHERE activity_type IN (...)` в SQL.

- 🟡 **#72. `$GLOBALS['db'] ?? getDBConnection()`** часто в проекте — anti-pattern (DI был бы чище), но это системно для всего PlanRun.

- 🟢 **#73. `enforceRaceDayConsistency` (L703-777) + `placeRaceOnCalendarDate` (L932-1053)** — много логики по «безопасному» размещению race-дня. Хорошо документированы safety-nets. Сложно, но видна забота о домене.

#### `DeepSeekPlanPlanner`

- 🔴 **#74. `finish_reason === 'length'` hard-throws** ([DeepSeekPlanPlanner.php:511-513](../planrun-backend/planrun_ai/llm_planner/DeepSeekPlanPlanner.php#L511-L513))
  ```php
  if ($finishReason === 'length') {
      throw new RuntimeException('DeepSeek planner response was truncated...');
  }
  ```
  Не пробует retry с большим лимитом или с укороченным контекстом. Каждый раз = failed job, который попадёт в очередь retry и снова упрётся в тот же лимит. Hard fail без recovery.

- 🟡 **#75. `PLAN_LLM_THINKING_ALWAYS=true` default** ([DeepSeekPlanPlanner.php:170](../planrun-backend/planrun_ai/llm_planner/DeepSeekPlanPlanner.php#L170))
  По умолчанию ВСЕГДА deepseek-reasoner с enable_thinking. Это 4-7 минут latency + ~10× стоимость per request. Хорошо для качества, но если queue worker один — propagation through queue замедляется. Auto-эскалация (Phase C.1) была разумнее.

- 🟡 **#76. `lastUsage` инстансная переменная** (L35) — после нескольких вызовов перезапишется. Если оркестратор делает `regenerateWeeks` после `generate` — usage первого вызова потерян. Не критично, но teardown с накоплением был бы лучше.

#### `plan_generator.php` (legacy)

- 🟡 **#77. Дублирование critique pipeline** — `applyCritiquePassToPlanData` (L117) делает то же что `PlanGenerationProcessorService::applyPlanCritique` (со своими build-helpers). Один из двух путей dead, но оба остаются.

- 🟡 **#78. `parseAndRepairPlanJSON`** (L250-300) — 5 уровней fallback парсинга. Хорошо для legacy LLM. Дублирует `repairAndParseCritiqueJson` (plan_critique_generator.php:167).

- 🟢 **#79. Большой raw SQL для users** (L42-55) — 30+ полей выбирается явно. Если добавится поле — нужно дописывать. UserRepository::getForPlanning делает то же чище.

#### `plan_saver.php`

- 🔴 **#80. `$alreadyNormalized=true` пропускает валидацию** ([plan_saver.php:30-36](../planrun-backend/planrun_ai/plan_saver.php#L30-L36))
  Если caller передаёт `true`, нормализатор пропускается. **Гарантия валидности — кооперативная**: если PlanQualityGate вернул `disable_repairs=true`, но caller ошибся с флагом — данные пишутся «как есть». Защиты нет.

- 🟡 **#81. Огромное дублирование `saveTrainingPlan` vs `saveRecalculatedPlan`** — циклы по weeks/days/exercises идентичны на 80% (L61-149 vs L257-345, ~80 строк). Извлечь в `writeWeeksToDb()`.

- 🟢 **#82. `DELETE`+`INSERT` каждый раз** — для recalculate не критично (только будущие недели), для full save — heavy. На план 16 недель × 7 дней × ~5 exercises = ~600 INSERT'ов внутри транзакции.

#### `plan_critique_generator.php`

- 🟡 **#83. Длинный prompt в коде** — system prompt critique на ~80 строк inline в файле (L51-105). Тестировать/менять через PR неудобно. Вынести в `prompts/critique.txt`.

- 🟢 **#84. `repairAndParseCritiqueJson`** — 4 fallback'а парсинга. Хорошо, но дублирует `plan_generator::parseAndRepairPlanJSON`.

- 🟡 **#85. `validateRevisedPlan` heuristics** — race-day removal protection, long count check, race-week training count. Хороший safety-net, но магические числа (60% сохранения, 2 training days в race-week).

#### `plan_normalizer.php`

- 🟡 **#86. 1791 строк в одном файле** — функциональный стиль (нет классов). Можно разбить на `Normalizer`, `LoadRepairs`, `PaceRepairs`, `DistanceRepairs`. Hard to test atomically.

- 🟡 **#87. Глобальные функции** — без namespace. Конфликт имён с другим кодом возможен.

#### `prompt_builder.php`

- 🔴 **#88. 3538 строк в одном файле — критический tech-debt**. Содержит:
  - VDOT calc и предсказания
  - Macrocycle planning (computeMacrocycle 318 строк)
  - Goal realism assessment
  - 15+ build* функций для частей промпта
  - Schedule helpers (computeRaceDayPosition, getPromptWeekdayOrder)

  Должно быть разбито на ~8 файлов:
  - `vdot.php` — формулы Daniels
  - `macrocycle.php` — фазы плана
  - `goal_realism.php` — assessment
  - `weekday_helpers.php`
  - `prompts/builder.php` — буквальная сборка
  - `prompts/blocks/*.php` — каждая build*-функция отдельно

  В таком виде один маленький правке в prompt-engineering требует пересмотра всех ~3.5K строк.

#### `ofp_enricher.php`

- 🟡 **#89. `$GLOBALS['db'] ?? getDBConnection()`** (L82) — тот же anti-pattern.

- 🟢 **#90. LLM-call для каждой генерации плана** — это +1 LLM call ~30-60s. Если LLM упадёт, есть `force-inject` template fallback. OK.

### Phase 2 — Сводная таблица

| # | Файл:строка | Тяжесть | Категория | Краткое описание |
|---|---|---|---|---|
| 52 | planrun_ai_integration.php:22 | 🔴 | bug | `MAX_TOKENS_HARD_LIMIT=4096` default режет планы |
| 53 | planrun_ai_integration.php:126 | 🔴 | bug | `$httpCode` undefined в catch первой попытки |
| 67 | PlanQualityGate.php:78-80 | 🔴 | bug | `applyTrainingStateLoadRepairs` вызвана дважды |
| 71 | PlanGenerationProcessorService.php:1104 | 🔴 | perf | Фильтр activity_type в PHP после SQL |
| 74 | DeepSeekPlanPlanner.php:511 | 🔴 | bug | finish_reason='length' hard-throws без retry |
| 80 | plan_saver.php:30 | 🔴 | safety | `$alreadyNormalized=true` пропускает валидацию полностью |
| 88 | prompt_builder.php | 🔴 | tech-debt | 3538 строк в одном файле — критический долг |
| 54 | planrun_ai_integration.php:87 | 🟡 | security | SSL verify disabled |
| 55 | planrun_ai_integration.php:57 | 🟡 | resilience | Linear backoff без jitter |
| 59 | pace_validator.php:67 | 🟡 | dead-code | `$min === 0` dead branch |
| 60 | validators/* | 🟡 | maintenance | Десятки магических чисел без config |
| 61 | PlanGenerationQueueService.php:326 | 🟡 | fragile | Heuristic парсинг error message |
| 63 | PlanReadinessCheckService.php | 🟡 | perf | `CREATE TABLE IF NOT EXISTS` на каждый вызов |
| 65 | PlanSkeletonBuilder.php:428 | 🟡 | complexity | resolveQualityTypes — 170 строк, 8+ branches |
| 68 | PlanQualityGate.php:691 | 🟡 | DRY | Дублирует словарь en-терминов из ChatActionParser |
| 69 | PlanQualityGate.php:771 | 🟡 | safety | Permissive policy не блокирует медицинские риски |
| 70 | PlanGenerationProcessorService.php:44 | 🟡 | cleanup | Dead legacy path после Phase D.3 |
| 72 | PlanGenerationProcessorService.php | 🟡 | coupling | `$GLOBALS['db']` anti-pattern |
| 75 | DeepSeekPlanPlanner.php:170 | 🟡 | cost/latency | `PLAN_LLM_THINKING_ALWAYS=true` default = 4-7 мин/4-10× цена |
| 76 | DeepSeekPlanPlanner.php:35 | 🟡 | obs | `lastUsage` теряет историю при множественных вызовах |
| 77 | plan_generator.php:117 | 🟡 | DRY | Дубль critique pipeline с PlanGenerationProcessor |
| 78 | plan_generator.php:250 | 🟡 | DRY | parseAndRepairPlanJSON vs repairAndParseCritiqueJson |
| 81 | plan_saver.php | 🟡 | DRY | Save/Recalculate дублируют ~80 строк |
| 83 | plan_critique_generator.php:51 | 🟡 | maintenance | Длинный inline prompt вместо файла |
| 85 | plan_critique_generator.php:351 | 🟡 | magic | Sanity-check thresholds 60% / 2 training days |
| 86 | plan_normalizer.php | 🟡 | tech-debt | 1791 строк, функциональный стиль |
| 87 | plan_normalizer.php | 🟡 | hygiene | Глобальные функции без namespace |
| 89 | ofp_enricher.php:82 | 🟡 | coupling | $GLOBALS['db'] |
| 56 | generate_plan_async.php | 🟢 | dead-leaning | Legacy CLI entry, дубль processor |
| 57 | generate_plan_async.php:21 | 🟢 | hygiene | Reason/goals tempfiles могут оставаться при сбое |
| 58 | text_generator.php | 🟢 | dead-code | LLM-ветка использует несуществующий контракт |
| 62 | PlanGenerationQueueService.php:289 | 🟢 | perf | SHOW TABLES на каждый вызов |
| 64 | PlanReadinessCheckService.php | 🟢 | magic | Жёсткие окна 2/21/10/5 дней |
| 66 | PlanSkeletonBuilder.php:6 | 🟢 | docs | DEFAULT_RUN_DAY_ORDERS — спортивная экспертиза, ОК как const |
| 73 | PlanGenerationProcessorService.php:703 | 🟢 | docs | enforceRaceDayConsistency — хорошо документирован |
| 79 | plan_generator.php:42 | 🟢 | DRY | Raw SQL для users vs UserRepository |
| 82 | plan_saver.php | 🟢 | perf | Heavy DELETE+INSERT каждый раз |
| 84 | plan_critique_generator.php:167 | 🟢 | DRY | Дубль JSON parser fallback |
| 90 | ofp_enricher.php | 🟢 | obs | +1 LLM call (30-60s) на каждую генерацию |

**Итого 39 находок в Phase 2: 7 критичных, 22 средних, 10 мелочей.**

### Главные рекомендации по Phase 2

1. **Срочно**: поправить `PLANRUN_AI_MAX_TOKENS_HARD_LIMIT` default (#52) или вообще убрать hard cap. Проверить, что в `.env.production` он задан выше 16384.
2. **Срочно**: разобраться с дублированным `applyTrainingStateLoadRepairs` (#67) — намеренно или опечатка.
3. **Срочно**: исправить `$httpCode` undefined (#53) в первой попытке retry.
4. **Срочно**: исправить `finish_reason='length'` hard-throw (#74) — добавить retry с увеличенным лимитом или alarm.
5. **Архитектурно**: разбить `prompt_builder.php` (#88) — 3500 строк в одном файле блокирует развитие. Текущая структура хранит вместе VDOT-формулы, periodization, prompt assembly — три ортогональные обязанности.
6. **Архитектурно**: убрать legacy paths (#56, #70, #77, #78) — после Phase D.3 они не должны вызываться в production; продолжение поддержки = риск, что кто-то случайно включит.
7. **Performance**: фильтровать activity_type в SQL (#71); кэшировать `assertQueueTableAvailable` (#62) и `ensureSchema` (#63).
8. **Safety**: пересмотреть `permissive` blocking policy (#69) — медицинские риски (marathon long_run too close to race) не должны downgrade'иться до warning.

---

## Phase 3 — Coaching + observability

> **Скоуп**: только AI-коучинг подсистема. `CoachController.php` (324) + `CoachService.php` (854) описывают **маркетплейс живых тренеров** (listCoaches/requestCoach/applyCoach/groups), не AI — они вне аудита AI-пайплайна и в финальный список Phase 3 не входят.

### Файлы фазы

| Файл | Строк | Роль |
|---|---|---|
| [`services/PostWorkoutFollowupService.php`](../planrun-backend/services/PostWorkoutFollowupService.php) | 1528 | Schedule → wait → send → snooze → reply → analyze → store |
| [`services/ProactiveCoachService.php`](../planrun-backend/services/ProactiveCoachService.php) | 797 | Detect events + daily briefing + weekly digest |
| [`services/AiPlanGenerationEventLogger.php`](../planrun-backend/services/AiPlanGenerationEventLogger.php) | 541 | Structured plan-generation observability + cohort metrics |
| [`services/AthleteSignalsService.php`](../planrun-backend/services/AthleteSignalsService.php) | 424 | Sigma feedback + day/week notes → risk score |
| [`services/AiObservabilityService.php`](../planrun-backend/services/AiObservabilityService.php) | 76 | Generic events в `ai_runtime_events` |

_Итого: 3 366 строк._

### Архитектура

```
[Cron worker tick]
    ├─ ProactiveCoachService::processDailyBriefings  (morning)
    │     └─ для каждого active user: getPlanForToday → LLM-вызов → addAIMessageToUser
    ├─ ProactiveCoachService::processWeeklyDigests  (раз в неделю)
    │     └─ countPlanned + getActualWorkoutStats → LLM-вызов
    ├─ ProactiveCoachService::processAllUsers  (event-driven)
    │     └─ detectEvents: pause | overload | race_approaching | low_compliance |
    │                      distance_record | goal_milestones (от GoalProgressService)
    │     └─ orderEventsByPriority + isOnCooldown → LLM (generateMessage) → send
    └─ PostWorkoutFollowupService::processDueFollowups  (caждые ~10 мин)
          ├─ expireStaleSentFollowups (status='sent' > 36ч → expired)
          ├─ для каждого pending due_at <= NOW:
          │     ├─ getWorkoutSummary + shouldScheduleForSummary
          │     ├─ buildFollowupPrompt → создать сообщение в чате
          │     └─ markFollowupStatus(sent) + recordSentAt
          └─ (когда пользователь отвечает в чат:)
              ChatService::streamResponse → tryHandlePostWorkoutFollowupReply
                 └─ isLikelyFeedbackResponse + getLatestAwaitingReply
                 └─ analyzeFeedback (regex: pain/fatigue/positive)
                 └─ NoteRepository::addDayNote + appendFeedbackToWorkoutLogIfPossible
                 └─ buildCoachReply → assistant content для чата
                 └─ UPDATE status='completed' + classification + scores

[Plan generation] (Phase 2 pipeline)
    └─ PlanGenerationProcessorService.process
          ├─ AiObservabilityService.logEvent('plan_generation', 'process', ...)
          ├─ ...
          └─ AiPlanGenerationEventLogger.recordSuccess / recordFailure
                └─ ai_plan_generation_events: cohort + model + duration + tokens
                                              + gate_mode + repairs + issue_codes

[Chat] (Phase 1 pipeline)
    └─ LlmGateway.requestChatCompletion
          └─ AiObservabilityService.logEvent('chat', 'llm_request', ...)

[Plan context for chat / coaching]
    └─ AthleteSignalsService.getRecentSignalsSummary(userId, 14)
          ├─ PostWorkoutFollowupService.getFeedbackAnalyticsBetween
          └─ AthleteNotesParser (regex: pain|fatigue|sleep|illness|stress|travel)
          → risk_level + planning_biases + highlights → передаётся в DeepSeek FACTS_JSON
```

### Найденные проблемы

#### `AiObservabilityService.php`

- 🟡 **#91. Silent fail при prepare** ([AiObservabilityService.php:59-61](../planrun-backend/services/AiObservabilityService.php#L59-L61))
  ```php
  $stmt = $this->db->prepare(...);
  if (!$stmt) {
      return;
  }
  ```
  Если `ai_runtime_events` таблица не создалась (например, ensureSchema потерпело fail), все события теряются молча. Стоит вызвать `logError` при первой prepare-failure.

- 🟢 **#92. `random_bytes(6)`** (L38) — 12 hex chars trace ID. На потоке 10/s ~46 лет до коллизии — OK.

#### `AthleteSignalsService.php`

- 🟡 **#93. Regex-based note analysis с hardcoded русским** ([AthleteSignalsService.php:185-249](../planrun-backend/services/AthleteSignalsService.php#L185-L249))
  `analyzeNote` — 6 паттернов (pain/fatigue/sleep/illness/stress/travel) с длинными регэкспами. Не учитывает negation: «никогда не болит» → классифицируется как `pain`. Стоит добавить отрицательный lookbehind хотя бы для базовых отрицаний.

- 🟡 **#94. Risk weights магические** (L197-218) — `0.70/0.55/0.30/...` без таблицы калибровки. Если кто-то изменит — невозможно понять, что изменилось функционально. Вынести в config с комментариями.

- 🟢 **#95. Output structure 35+ ключей** (L274-303) — флаги дублируются (`has_note_pain_signal` + `note_pain_count`). Для DeepSeek FACTS_JSON избыточно, но не критично.

#### `AiPlanGenerationEventLogger.php`

- 🔴 **#96. 28-параметрный `bind_param` опасен** ([AiPlanGenerationEventLogger.php:413](../planrun-backend/services/AiPlanGenerationEventLogger.php#L413))
  ```php
  $stmt->bind_param(
      'isssssiisiiiiiisssisssssssss',  // 28 chars
      $userId, $jobType, ..., $metadataJson  // 28 параметров
  );
  ```
  Если когда-то добавится поле — нужно поменять type string + переменные + INSERT. Высокий риск рассинхронизации. Перейти на name-bindings (PDO) или хотя бы вынести в массив `[$type => $value]` и собирать строку программно.

- 🟡 **#97. `isDeepSeekOffPeakNow` hardcoded window** (L293-299) — 16:30-00:30 UTC. Если DeepSeek изменит политику — нужен deploy. Сделать env-конфигурируемым.

- 🟢 **#98. `JSON_LENGTH(applied_repair_codes) > 0`** (L190, L210) — требует MySQL 5.7+. OK для современного, но не работает на 5.6.

#### `ProactiveCoachService.php`

- 🟡 **#99. `getActiveUsers` без пагинации** ([ProactiveCoachService.php:779-791](../planrun-backend/services/ProactiveCoachService.php#L779-L791))
  Берёт всех пользователей сразу. На 1000+ юзерах это OK по памяти, но один tick может занять часы. Стоит добавить LIMIT + OFFSET + чекпойнт.

- 🟡 **#100. Cooldown race condition** ([ProactiveCoachService.php:443-471](../planrun-backend/services/ProactiveCoachService.php#L443-L471))
  Между `isOnCooldown` (SELECT) и `recordCooldown` (INSERT) нет блокировки. При параллельных воркерах оба могут пройти проверку и отправить duplicate-сообщение пользователю. Либо запустить процесс в одном экземпляре, либо использовать `INSERT ... ON DUPLICATE KEY UPDATE` с UNIQUE KEY на `(user_id, event_type, day)`.

- 🟡 **#101. `pause` detection считает ВСЕ workouts** ([ProactiveCoachService.php:131-152](../planrun-backend/services/ProactiveCoachService.php#L131-L152))
  Запрос «последняя тренировка» включает и `workouts` (Strava — там может быть прогулка/вело), и `workout_log` (ручная отметка). По задумке pause = «нет бега», но велик/ходьба обнуляют счётчик. Атлет, который перешёл на велик из-за травмы, не получит pause-сообщения.

- 🟡 **#102. Один event-type per tick** ([ProactiveCoachService.php:72](../planrun-backend/services/ProactiveCoachService.php#L72))
  `pickNextAvailableEvent` берёт первый по приоритету; остальные ждут следующего цикла. При cooldown 48ч и одновременных `overload`+`distance_record` сообщение о рекорде дойдёт через 2 дня. Стоит отправлять до 2-3 непересекающихся типов за tick.

- 🟢 **#103. `require_once ChatContextBuilder.php` в горячем цикле** (L155, L188, L509, L606) — несколько вызовов на user. Lazy-load через DI был бы чище.

- 🟢 **#104. `normalizeProse`** (L707-724) — пост-обработка LLM-вывода: убирает markdown bold/italic, ведущие маркеры. Хорошая защита от формат-leak.

#### `PostWorkoutFollowupService.php`

- 🔴 **#105. `analyzeFeedback` без negation handling** ([PostWorkoutFollowupService.php:751-820](../planrun-backend/services/PostWorkoutFollowupService.php#L751-L820))
  ```php
  $painVerbDetected = (bool) preg_match('/болит|забол|тянет|...', $normalized);
  ```
  Текст «после массажа уже ничего не болит» — `painVerbDetected=true` → `painFlag=true` → recovery_risk_score=0.84+ → `classification='pain'`. Это запишется в `post_workout_followups.pain_flag=1` и далее **попадёт в `findLatestStalePainSignal` через 7 дней** → `PlanReadinessCheckService` создаст pending check-in → блокирует recalculate.
  Negation-aware regex (например, lookbehind на «не\s+» в окне 30 символов) хотя бы для базовых случаев.

- 🟡 **#106. `isLikelyFeedbackResponse` ловит произвольные ответы** (L841)
  Heuristic — если пользователь после followup-сообщения ответил «что у меня сегодня по плану?», он может попасть под `isLikelyFeedbackResponse=true` и закрыть followup как `completed` без реального ответа. Сообщение пользователя сохранится как «самочувствие». Стоит явно дифференцировать.

- 🟡 **#107. `ensureSchema` + 10 `ensureColumnExists` каждый вызов** ([PostWorkoutFollowupService.php:72-82](../planrun-backend/services/PostWorkoutFollowupService.php#L72-L82))
  Каждый вызов первой публичной функции дёргает 10 проверок колонок. Это runtime schema-management, нет миграционных файлов. На холодном MySQL это +50-100ms latency, плюс антипаттерн — производственная схема должна жить в `scripts/migrate_all.php`.

- 🟡 **#108. `appendFeedbackToWorkoutLogIfPossible` race** ([PostWorkoutFollowupService.php:712-745](../planrun-backend/services/PostWorkoutFollowupService.php#L712-L745))
  Read `notes` → check substring → write merged. Параллельная запись теряет одно из обновлений. Не критично (followup идёт в очередь), но проблема архитектурная.

- 🟡 **#109. `supersedeOtherActiveFollowups`** (L521) — при scheduling нового followup'а старые pending/sent → `skipped`. Если у пользователя 2 разных workouts за день (утро+вечер), второй сразу затирает первый. Это намеренно?

- 🟢 **#110. `getReplyWindowHours` 36h default** (L1128) — после 36 часов sent → expired. Если пользователь отвечает на 37-м часе — ответ не привязывается, идёт как обычное сообщение. Magic constant.

- 🟢 **#111. `isFirstUserReplyAfterFollowup`** (L575-592) — корректно проверяет, что нет промежуточных user-сообщений между followup и текущим ответом. Хорошая защита.

- 🟢 **#112. `recovery_risk_score` поднимается до 0.95** при словах «сильн/остр/прострел/не могу/хром» (L800) — нет валидации, что эти слова в контексте боли (могут быть в шутке). False positive.

### Phase 3 — Сводная таблица

| # | Файл:строка | Тяжесть | Категория | Краткое описание |
|---|---|---|---|---|
| 96 | AiPlanGenerationEventLogger.php:413 | 🔴 | safety | 28-параметрный `bind_param` хрупкий к изменениям |
| 105 | PostWorkoutFollowupService.php:751 | 🔴 | bug | `analyzeFeedback` не учитывает negation («не болит» → pain) |
| 91 | AiObservabilityService.php:59 | 🟡 | obs | Silent fail при prepare — все события теряются |
| 93 | AthleteSignalsService.php:185 | 🟡 | quality | Note-regex без negation |
| 94 | AthleteSignalsService.php:197 | 🟡 | maintenance | Risk weights магические |
| 97 | AiPlanGenerationEventLogger.php:293 | 🟡 | config | DeepSeek off-peak window hardcoded |
| 99 | ProactiveCoachService.php:779 | 🟡 | perf | getActiveUsers без пагинации |
| 100 | ProactiveCoachService.php:443 | 🟡 | race | Cooldown race condition между воркерами |
| 101 | ProactiveCoachService.php:131 | 🟡 | bug | pause detection считает любую активность, не только бег |
| 102 | ProactiveCoachService.php:72 | 🟡 | quality | Один event-type per tick — низкоприоритетные тонут |
| 106 | PostWorkoutFollowupService.php:841 | 🟡 | bug | isLikelyFeedbackResponse ловит non-feedback ответы |
| 107 | PostWorkoutFollowupService.php:72 | 🟡 | hygiene | Runtime schema-management вместо миграций |
| 108 | PostWorkoutFollowupService.php:712 | 🟡 | race | Read-check-write append без блокировки |
| 109 | PostWorkoutFollowupService.php:521 | 🟡 | design | supersedeOtherActiveFollowups затирает workout 2 при scheduling workout 3 |
| 92 | AiObservabilityService.php:38 | 🟢 | docs | `random_bytes(6)` — entropy ОК |
| 95 | AthleteSignalsService.php:274 | 🟢 | cleanup | 35+ ключей output structure |
| 98 | AiPlanGenerationEventLogger.php:190 | 🟢 | compat | JSON_LENGTH требует MySQL 5.7+ |
| 103 | ProactiveCoachService.php:* | 🟢 | DRY | require_once в горячем цикле |
| 104 | ProactiveCoachService.php:707 | 🟢 | docs | normalizeProse — хорошая защита |
| 110 | PostWorkoutFollowupService.php:1128 | 🟢 | magic | reply_window 36h hardcoded |
| 111 | PostWorkoutFollowupService.php:575 | 🟢 | docs | isFirstUserReplyAfterFollowup — корректная защита |
| 112 | PostWorkoutFollowupService.php:800 | 🟢 | bug | Слова «сильн/остр» вне контекста боли — false positive risk score |

**Итого 22 находки в Phase 3: 2 критичные, 13 средних, 7 мелочей.**

### Главные рекомендации по Phase 3

1. **Срочно**: исправить negation handling в `analyzeFeedback` (#105) — баг блокирует recalculate и создаёт false-positive pain signals в plan readiness check.
2. **Срочно**: рефакторить 28-параметрный bind_param (#96) — критическая точка ломкости.
3. **Архитектурно**: вынести schema management из runtime в миграции (#107). PlanReadinessCheckService и PostWorkoutFollowupService оба грешат тем же.
4. **Coаching качество**: добавить SQL-фильтр activity_type='running' в pause detection (#101). Сейчас атлет с травмой, перешедший на велик, не получит проактивного сообщения.
5. **Конкурентность**: cooldown race (#100) — добавить UNIQUE KEY `(user_id, event_type, day)` на `proactive_coach_log` либо обернуть в advisory lock через `GET_LOCK`.

---

## Итоговая сводка

**Всего находок: 112** в 30+ файлах, ~28 000 строк:

| Фаза | Файлов | Строк | 🔴 крит | 🟡 средн | 🟢 мелочи | Всего |
|---|---|---|---|---|---|---|
| Phase 1 — Chat | 9 | 6 148 | 6 | 26 | 19 | 51 |
| Phase 2 — Plan generation | 22 | ~14 000 | 7 | 22 | 10 | 39 |
| Phase 3 — Coaching + observability | 5 | 3 366 | 2 | 13 | 7 | 22 |
| **Итого** | **36** | **~23 500** | **15** | **61** | **36** | **112** |

### Сквозные паттерны через все фазы

1. **Runtime schema management** (#63, #97, #107, plus `LlmGateway::ensureLimiterTable`, `AiObservabilityService::ensureSchema`) — 5 разных мест создают/мигрируют таблицы на каждый вызов вместо `scripts/migrate_all.php`. Должно быть централизовано.

2. **Регекс-парсеры русского текста с hardcoded паттернами** (ChatActionParser, ChatConfirmationHandler, AthleteSignalsService, PostWorkoutFollowupService) — каждый со своим словарём, без negation handling. Антипаттерн при наличии DeepSeek для классификации.

3. **Дублирование JSON-parse fallback логики** (#78, #84, в plan_generator и plan_critique_generator) — `repairAndParseCritiqueJson` ≈ `parseAndRepairPlanJSON`.

4. **Дублирование SQL workouts/workout_log UNION** в 5+ местах (ChatContextBuilder, ProactiveCoachService, PlanGenerationProcessorService, DeepSeekPlanPlanner, и др.) — общий `WorkoutQueryBuilder` уберёт ~500 строк.

5. **Магические числа без config**: cooldowns, thresholds, windows, ratios — десятки. Минимум один `coaching_config.php` для тренерских порогов.

6. **`$GLOBALS['db'] ?? getDBConnection()`** (#72, #89, plus в plan_critique_generator, ofp_enricher) — anti-pattern. Системный для проекта, но Phase 3 точки делают invocation race-prone.

7. **Dual-path: legacy + новый** (Phase 1: stream vs non-stream; Phase 2: llm_planner vs generatePlanViaPlanRunAI; Phase 3: единственный) — везде новый путь развит лучше, legacy остаётся как fallback но dead-leaning.

### Приоритеты по фиксу (топ-10)

1. 🔴 [ChatService.php:237](../planrun-backend/services/ChatService.php#L237) — `$db->insert_id` для message_id
2. 🔴 [ChatConfirmationHandler.php:118](../planrun-backend/services/ChatConfirmationHandler.php#L118) — `'assistant'` vs `'ai'` sender_type — dead method
3. 🔴 [ChatService.php:202](../planrun-backend/services/ChatService.php#L202) — Memory + confirmation handlers только в stream-пути
4. 🔴 [PostWorkoutFollowupService.php:751](../planrun-backend/services/PostWorkoutFollowupService.php#L751) — `analyzeFeedback` без negation (создаёт false pain signals)
5. 🔴 [planrun_ai_integration.php:22](../planrun-backend/planrun_ai/planrun_ai_integration.php#L22) — `MAX_TOKENS_HARD_LIMIT=4096` default режет планы
6. 🔴 [PlanQualityGate.php:78](../planrun-backend/services/PlanQualityGate.php#L78) — `applyTrainingStateLoadRepairs` вызвана дважды
7. 🔴 [DeepSeekPlanPlanner.php:511](../planrun-backend/planrun_ai/llm_planner/DeepSeekPlanPlanner.php#L511) — `finish_reason='length'` hard-throw
8. 🔴 [LlmGateway.php:797](../planrun-backend/services/LlmGateway.php#L797) — `sleepBeforeRetry` cap 30s игнорирует длинный Retry-After
9. 🔴 [ChatMemoryManager.php:205](../planrun-backend/services/ChatMemoryManager.php#L205) — race при concurrent extraction
10. 🔴 [plan_saver.php:30](../planrun-backend/planrun_ai/plan_saver.php#L30) — `$alreadyNormalized=true` пропускает валидацию

### Архитектурные приоритеты (3-6 мес работы)

1. Разбить [`prompt_builder.php`](../planrun-backend/planrun_ai/prompt_builder.php) (3538 строк) на 5-8 файлов по обязанностям.
2. Убрать regex-based confirmation handlers (#27) — заменить на структурированный `propose_action(...)` tool.
3. Централизовать schema management в `scripts/migrate_all.php`.
4. Выделить `WorkoutQueryBuilder` — закроет 5+ дублей SQL.
5. После Phase D.3 удалить legacy plan-generation путь — `generatePlanViaPlanRunAI` и обвязка.
6. Заменить русские regex-парсеры самочувствия (`analyzeFeedback`, `analyzeNote`) на классификацию через быструю LLM (deepseek-v4-flash) — будет надёжнее с negation, контекстом, smiles.

---

**Аудит завершён 2026-05-15.** Документ — read-only зафиксированная картина состояния AI-пайплайна на 2026-05-15. При фиксе любой из проблем — обновлять [`MEMORY.md`](../../home/st_benni/.claude/projects/-var-www-planrun/memory/MEMORY.md), но не сам этот документ (он точка во времени, не текущая истина).
