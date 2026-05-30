# Handoff: Редизайн чата PlanRun

## Что это

Редизайн экрана чата PlanRun (AI-тренер + живой тренер + администрация), на основе текущего `ChatScreen.jsx`. В `prototype/` — рабочий HTML-прототип; откройте `PlanRun Redesign v2.html` в браузере и листайте до секции **«Бегун · Чат (v3)»** (6 артбордов). Кнопка ☀/🌙 справа вверху переключает тему.

**Это design reference, не production-код.** Воссоздайте в существующем окружении (React 18 + Zustand, `ChatScreen.jsx` + хуки `screens/chat/*`, токены из `sports-colors.css`).

---

## 6 артбордов

| Артборд | Что показывает |
|---|---|
| Десктоп · AI-тренер | 2 колонки: список чатов (AI / тренер / администрация) + диалог |
| Десктоп · чат с тренером | тот же layout, выбран тренер |
| Мобайл · AI-чат + tool-result | диалог с зелёной карточкой результата tool-call |
| Мобайл · пустой AI-чат | большая AI-иконка + 6 suggested prompts |
| Мобайл · AI вызывает инструмент | live-спиннер «Меняю дни местами…» |
| Мобайл · чат с тренером | context-strip с сегодняшней тренировкой |

---

## Ключевые решения (что меняем vs текущий ChatScreen)

### 1. Tool-calling — first-class визуал
Текущий код уже стримит фазы (`tool:update_training_day` → label «Обновляю тренировку…»). Редизайн делает это заметным:
- **Live-индикатор**: оранжевый bubble со спиннером + «Меняю дни местами…» пока tool выполняется
- **Result-карточка** после завершения: зелёная плашка «✓ Тренировка перенесена · на 8:00» с кнопкой «Открыть» (ведёт в календарь на изменённый день)
- 6 tool-labels уже есть в коде (`TOOL_LABELS` в `ChatScreen.jsx`) — переносятся as-is

### 2. Capabilities banner в AI-чате
Над диалогом — «★ AI может изменять твой план» + чипы (✎ править · ↔ переносить · ✓ отмечать · 🔄 пересчитать). Юзеры не знают, что AI реально правит план — это нужно показать.

### 3. Пустой AI-чат → suggested prompts
Берём существующий `SUGGESTED_PROMPTS` из `chatQuickReplies.js`, рендерим как карточки с эмодзи. Большая AI-иконка + заголовок.

### 4. Контекстные quick-replies
Существующая логика `getQuickReplies(aiMessage)` (regex по последнему сообщению AI) — оставить, рендерить как pill-кнопки над композером.

### 5. Чат с тренером — context-strip
Сверху прикреплена сегодняшняя тренировка (тренер обычно отвечает на неё) — «4×1 км в темпе · 8 км · Открыть →». Online-статус + среднее время ответа в шапке.

### 6. Композер
Фото (ImageIcon) + emoji-picker + голос (MicIcon) — всё уже есть в коде (`ChatComposerInput`, `ChatEmojiPicker`, `VoiceMessage`, `useVoiceRecorder`). Send-кнопка превращается в голос когда поле пустое.

### 7. Мобайл: композер на всю нижнюю кромку
**Важно:** при входе в диалог нижний навбар (`BottomNav`) скрывается, композер занимает низ экрана. Класс `chat-keyboard-open` уже есть для keyboard-aware поведения.

---

## Liquid Glass + темы

- Bubble AI/тренер: `rgba(255,255,255,0.72)` + `blur(14px)`, bubble юзера: solid primary `#FC4C02`
- Композер, шапка, sidebar: glass с blur
- Тёмная тема: всё через `[data-theme="dark"]` — bubble становится `rgba(28,34,43,0.62)`, фон — warm radial над `#0F151D`
- Анимации: спиннер tool-call (`@keyframes spin`), typing-dots (`@keyframes bounce`)

---

## Файлы для правки

```diff
~ src/screens/ChatScreen.jsx               (вынести tool-result в компонент ToolResultCard)
~ src/screens/ChatScreen.css               (glass-bubbles, capabilities banner, dark)
~ src/screens/chat/chatQuickReplies.js     (без изменений — переиспользовать)
+ src/components/chat/ToolResultCard.jsx    (зелёная карточка после tool-call)
+ src/components/chat/CapabilitiesBanner.jsx
+ src/components/chat/SuggestedPrompts.jsx  (пустой AI-чат)
+ src/components/chat/ChatContextStrip.jsx  (сегодняшняя тренировка в чате тренера)
```

Код прототипа — `prototype/src/v3-chat.jsx` (читается как обычный React).

---

## Сохранить

- Стриминг через `ChatSSE` + фазы (connecting → streaming → tool:* → done)
- Все 15+ tool-labels из `TOOL_LABELS`
- `getQuickReplies` + `SUGGESTED_PROMPTS`
- Голосовые, картинки, emoji
- Pin-to-bottom, keyboard-aware

Прототип — первоисточник; всё кликабельно в браузере.
