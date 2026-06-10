# Дубли: frontend (верифицировано)

Дата: 2026-06-10. Входные данные: `_work/clones.txt` (39 из 134 групп содержат js/jsx из `src/`),
`_work/dup_candidates.md` (секция Frontend, ~20 семантических кандидатов), `DEAD-CODE-FRONTEND.md`.
Метод: каждый кандидат открыт и сверен построчно; регэкспы парсинга дополнительно проверены прогоном в node.

Итог по 39 clone-группам: 16 — обе стороны мёртвые, 8 — частично мёртвые, 15 — живые.

---

## 1. Критичные дубли (риск рассинхрона / уже разошлись)

### 1.1. Парсинг описания тренировки — 9 реализаций, 3 из них с реальными багами

Полную таблицу см. в разделе 3.1. Здесь — уже случившиеся расхождения:

**БАГ A — интервалы никогда не распознаются (сломанный `\b` после кириллицы):**
- `src/components/Dashboard/v3/WeekSectionV3.jsx:226` (`extractIntervals`)
- `src/components/Dashboard/v3/NextWorkoutSectionV3.jsx:143` (ветка `intervals` в `parseDescription`)

Обе копии используют `/(\d+)\s*[×x]\s*(\d+(?:[.,]\d+)?)\s*(км|м)\b/i`. В JS без флага `u`
`\b` после кириллической буквы «м» срабатывает только если дальше идёт латиница/цифра — проверено
в node: на строках `«4×1 км · 0:42:00»` и `«6×400 м, отдых 200 м»` регэксп **не матчится вообще**.
Следствие: в ленте недели и карточке «Следующая тренировка» интервальные лейблы вида «4×1 км в темпе»
никогда не строятся — всегда fallback на «Интервалы»/«Темповый X км». Ирония: в комментариях обоих
файлов написано «\b не работает с кириллицей — используем lookahead», но lookahead применён только к км.
Третья копия в `TodayHeroV3.jsx:303` использует `(к?м)` без `\b` — работает.

**БАГ B — неверная дистанция для интервальных тренировок в TodayHeroV3:**
- `src/components/Dashboard/v3/TodayHeroV3.jsx:286`: km-регэксп `/(\d+(?:[.,]\d+)?)\s*км(?=$|[\s·,.])/i`
  без префикса `(?:^|\s)`. На описании `«4×1 км …»` возвращает `km = 1` (проверено в node).
- Копия в `NextWorkoutSectionV3.jsx:131` префикс `(?:^|\s)` имеет и на той же строке корректно
  возвращает `null`. Классический форк: багфикс внесён в одну копию из трёх.

**БАГ C — ResultModal: урезанный форк парсинга интервалов из AddTrainingModal.**
`AddTrainingModal.jsx:360-374` vs `ResultModal.jsx:186-203` — одинаковая логика prefill, но в
AddTrainingModal позже добавлены fallback-паттерны, которых в ResultModal нет:
- темп разминки в скобках: `Разминка[^.]*\((\d{1,2}:\d{2})\)` (ATM:363) — в RM:189 отсутствует;
- темп интервала в скобках: `\((\d{1,2}:\d{2})\)` (ATM:367) — в RM:193 отсутствует;
- пауза в формате «отдых N м трусцой/ходьбой» (ATM:369) — в RM:195 только «пауза N м …».

Следствие: для одного и того же дня плана редактор тренировки предзаполняет поля, а «Отметить
тренировку» — нет. Рекомендация: вынести парсер в `src/utils/workoutDescriptionParser.js` (фартлек-ветки
у обоих уже байт-в-байт, ATM:375-387 ≡ RM:204-220).

### 1.2. daysToRace — 5 живых копий с разной семантикой дня гонки

| Копия | Поведение в день гонки |
|---|---|
| `Coach/AthleteOverlay.jsx:36`, `Dashboard/v3/GoalSectionV3.jsx:23` | `Math.max(0, …)` → возвращает 0 («0 дней») |
| `Coach/AthleteTable.jsx:52`, `Coach/AthleteGrid.jsx:29`, `Coach/CompareAthletesPanel.jsx:21` | `d > 0 ? d : null` → null (бейдж скрывается) |

Тренер видит в таблице и в оверлее одного и того же атлета разное состояние «до гонки».

### 1.3. DISTANCE_LABELS — 6 живых копий с разным покрытием ключей

- `AthleteTable.jsx:14` — единственная знает `'21.1k'`/`'42.2k'`;
- `GoalCountdownWidget.jsx:11` — знает `'21.1k'`/`'42.2k'`, но не знает `half_marathon` и `ultra`;
- `AthleteGrid.jsx:16`, `CompareAthletesPanel.jsx:16` — короткое «Полу», без `'21.1k'`;
- `AthleteOverlay.jsx:24`, `GoalSectionV3.jsx:16` — «Полумарафон», без `'21.1k'`;
- + `UserProfileScreen.jsx:34` (`RACE_DISTANCE_LABELS`).

Атлет с `race_distance='21.1k'` в таблице тренера показывается как «Полумарафон», в гриде/оверлее —
сырым ключом «21.1k».

### 1.4. SPEC_LABELS — 3 копии, лейблы уже разошлись

`screens/TrainersScreen.jsx:37` / `screens/trainers/FindTrainerV3.jsx:9` / `screens/profile/ProfileV3.jsx:20`.
Один и тот же ключ показывается по-разному: `injury_recovery` = «Травмы» (Trainers/FindTrainer) vs
«Восстановление» (ProfileV3); `beginner` = «Начинающие» vs «Новичкам»; `mental` = «Ментальные навыки» /
«Ментальные» / «Ментальная подготовка»; ключи `health`, `speed` есть только в FindTrainerV3.

### 1.5. TYPE_LABELS — локальные форки при существующем каноне

Канон `WORKOUT_TYPE_LABEL` есть в `src/utils/workoutTypes.js:1` (23 типа, включая `recovery`,
`long-run`, `ofp`, `cross`), его уже реэкспортируют `calV3.js:14` и `workoutFormUtils.js:88`. Но
`TodayHeroV3.jsx:19`, `NextWorkoutSectionV3.jsx:9`, `WeekSectionV3.jsx:13` держат идентичные локальные
TYPE_LABELS из 12 типов с другими текстами («Лёгкая» vs «Лёгкий бег», «Длительная» vs «Лонг», «Гонка»
vs «Соревнование») и без `recovery`/`long-run` — такой тип в дашборде упадёт в сырой ключ. Это же
clone-группа `NextWorkoutSectionV3:9-25 ↔ WeekSectionV3:13-31` (плюс TYPE_PROPER и
TYPE_INTERVAL_SUFFIX — байт-в-байт x2).

### 1.6. handleWorkoutClick/handleCloseSheet/handleDeleteWorkout — форк StatsScreen ↔ UserProfileScreen

`screens/StatsScreen.jsx:130-161` ≈ `screens/UserProfileScreen.jsx:310-333` (clone-группа ~13 строк).
Расхождение: `StatsScreen.handleDeleteWorkout:160` вызывает
`useWorkoutRefreshStore.getState().triggerRefresh()`, версия в UserProfileScreen — нет. Удаление
тренировки из профиля не инвалидирует кэши других экранов (дашборд/календарь). Также в StatsScreen
`handleWorkoutClick` не обёрнут в `useCallback`, в профиле — обёрнут.

### 1.7. Крипто-хелперы PIN: CredentialBackupService ↔ PinAuthService

`services/CredentialBackupService.js:21-75` ↔ `services/PinAuthService.js:21-84` (clone-группы ~29 и
~23 строки). Идентичны: константы PBKDF2/AES-GCM, `deriveKeyWithIterations`, `generateRandomBytes`,
`base64Encode/Decode`, `encodePayload/decodePayload` (v2-формат с миграцией legacy-итераций).
Расхождение: CredentialBackup при derive санитизирует PIN — `encoder.encode(String(pin).replace(/\D/g, ''))`
(:29), PinAuth кодирует как есть (:25). Пока PIN строго 4 цифры — эквивалентно, но любое изменение
формата PIN даст разные ключи в двух сервисах. Это security-критичный код — двойная копия означает
двойную поверхность для правок (как уже было с миграцией итераций 1000→120000, продублированной в оба
файла). Вынести в `src/services/cryptoUtils.js` (~50 строк на файл).

### 1.8. ChatScreen — тройной рендер сообщения чата

`screens/ChatScreen.jsx:953-970` (админ-чат) / `:1057-1082` (личный диалог) / `:1193-1230` (основной
чат) — три параллельных `.map()` с бабблом: date-separator (`dayKeyOf` + `formatDateSeparator`),
attachment, `EmojiText`, время. Копии уже отличаются фичами (proactive-label и tools — только в
третьей; sender-name — во второй и третьей с разным форматом: `getDisplayName(...)` vs голый
`msg.sender_username`). Каждая правка пузыря требует 3 синхронных правок. Рекомендация: компонент
`ChatMessageBubble` + общий маппер. Туда же: JSON-парс metadata продублирован трижды —
`getMessageAttachment:91`, `getMessageToolsUsed:103` (каждый парсит заново) и инлайн `msgMeta:1196`.

### 1.9. Escape + body-overflow-lock — 10 живых копий эффекта + расхождение с каноном

Один и тот же `useEffect` (Escape→onClose, `body.style.overflow='hidden'` с восстановлением prev):
`AddTrainingModal.jsx:704`, `ResultModal.jsx:718`, `Coach/AthleteOverlay.jsx:~112`,
`Coach/BulkAssignModal.jsx:71`, `Coach/CompareAthletesPanel.jsx:30`, `Coach/ConfirmConflictDialog.jsx:15`,
`Coach/EventQuickReplySheet.jsx:~48`, `Coach/GroupMessageDialog.jsx:~24`, `Coach/TemplateEditorModal.jsx:89`,
`Dashboard/v3/DashCustomizerV3.jsx:64`. Два варианта (с guard'ом `!busy` и без). Канонический
`common/Modal.jsx` делает то же, но **не сохраняет** предыдущий overflow (сбрасывает в `''`) — вложенные
модалки через Modal могут снять лок внешней шторки, копии — нет. Рекомендация: хук
`src/hooks/useModalDismiss.js` (`{ isOpen, onClose, busy }`), ~100 строк экономии.

---

## 2. Точные копии — кандидаты на вынос в общий util/hook

| Что | Все копии | Предлагаемое место | ~строк экономии |
|---|---|---|---|
| Крипто-хелперы PBKDF2/AES-GCM/base64/payload (см. 1.7) | CredentialBackupService.js:21-75, PinAuthService.js:21-84 | `src/services/cryptoUtils.js` | ~50 |
| JSX-список упражнений с чипами (sets×reps/вес/время) | Calendar/WorkoutCard.jsx:288-308, Stats/WorkoutDetailsModal.jsx:111-135 (копия осознанная — комментарий «те же классы», но это компонент, а не CSS) | `src/components/common/ExerciseChipsList.jsx` | ~25 |
| Эффект Escape+overflow-lock (см. 1.9) | 10 файлов | `src/hooks/useModalDismiss.js` | ~100 |
| Эффект клик-вне+Escape для поповеров | Calendar/v3/InfoTip.jsx:9-19 ≡ Calendar/v3/PlanActionsMenuV3.jsx:18-28 (байт-в-байт, `pointerdown`); chat/ChatHeaderMenu.jsx:15-27 (вариация на `mousedown`) | `src/hooks/useDismissable.js` | ~30 |
| `intervalTotalKm` + `fartlekTotalKm` (useMemo-калькуляторы) | AddTrainingModal.jsx:505-530 ≡ ResultModal.jsx:124-148 (отличия только в именах локальных переменных) | `src/utils/workoutFormUtils.js` | ~45 |
| Блок useState интервалов/фартлека | AddTrainingModal.jsx:112-125 ≡ ResultModal.jsx:66-79 | уйдёт вместе с выносом конструктора бега в общий компонент/хук | ~14 |
| `TYPE_LABELS`+`TYPE_PROPER`+`TYPE_INTERVAL_SUFFIX` | NextWorkoutSectionV3.jsx:9-28 ≡ WeekSectionV3.jsx:13-34 (+TYPE_LABELS ещё в TodayHeroV3.jsx:19) | `src/utils/workoutTypes.js` (канон уже там) | ~50 |
| `MONTHS_GEN` (родительный падеж) | NextWorkoutSectionV3.jsx:41 ≡ DashHeaderV3.jsx:10 ≡ GoalSectionV3.jsx:21 (полный) + короткие в PRSectionV3.jsx:17 / calV3.js:410 | `src/utils/dateLabels.js` | ~10 |
| `USER_DIST_TO_KEY` | GoalSectionV3.jsx:11 ≡ RacePredictionV3.jsx:17 | `src/utils/raceDistances.js` (вместе с DISTANCE_LABELS из 1.3) | ~8 |
| `isAiPlanMode` (однострочник `=== 'ai'`) | useDashboardData.js:13 ≡ DashboardV3.jsx:50 (третья копия в мёртвом Dashboard.jsx) | `src/utils/trainingMode.js` или экспорт из useDashboardData | ~6 |
| `firstLine` | Coach/AthleteOverlay.jsx:428 ≡ Coach/AthleteTable.jsx:46 | `src/components/Coach/coachHelpers.js` (уже есть и импортируется обоими) | ~8 |
| `formatRelative` ≡ `formatTime` (относительное время, сек-гранулярность) | AthleteOverlay.jsx:449 ≡ EventQuickReplySheet.jsx:27 (байт-в-байт) | `src/utils/relativeTime.js` | ~12 |
| `workoutDateStr` + `km` | Stats/statsAchievements.js:3-10 ≡ Stats/statsV3Utils.js:23-37 (байт-в-байт) | экспортировать из statsV3Utils и импортировать в statsAchievements | ~12 |
| `ymd` | calV3.js:162 ≡ statsV3Utils.js:281 ≡ `StatsUtils.formatDateStr:56` (statsV3Utils при этом **уже импортирует** formatDateStr из StatsUtils, но держит свой ymd) | `StatsUtils.formatDateStr` или `src/utils/date.js` | ~12 |
| PlanActionsMenuV3 JSX-вызов с 6 пропсами x2 | CalendarScreen.jsx:688-698 ≡ :715-725 | вынести в локальную переменную `planMenuNode` | ~12 |
| Загрузочный экран профиля x2 | UserProfileScreen.jsx:385-395 ≡ :417-427 (`loading` и `!profileUser` ветки идентичны) | объединить условие `if (loading \|\| !profileUser)` | ~12 |
| `useIsMobile`/`useIsDesktop` — копии `useMediaQuery` | Calendar/v3/useIsMobile.js (вся суть = `useMediaQuery('(max-width: 640px)')`), Stats/v3/StatsV3.jsx:18 `useIsDesktop` (= `useMediaQuery('(min-width: 1024px)')`, как уже делают SettingsV3/WorkoutSheet) | `hooks/useMediaQuery` (канон существует) | ~35 |

---

## 3. Параллельные реализации

### 3.1. Парсинг описания тренировки — 9 реализаций (главная таблица)

Что понимает каждая копия (✓ = парсит; «—» = не парсит; ✗ = регэксп есть, но сломан):

| # | Реализация (файл:строки) | X км | темп | время | пульс/ЧСС | разминка/заминка | N×M м/км | N×M мин/сек | пауза/отдых | фартлек-сегменты |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | AddTrainingModal.jsx:321-389 (prefill редактора) | ✓ | ✓ `темп:`/`M:SS /км`/`в темпе` | ✓ `или H:MM` + расчёт из темпа | ✓ `пульс N` | ✓ км+темп, темп в скобках `(5:50)` | ✓ `[×x]` | — | ✓ `пауза`+`отдых`, 3 типа | ✓ с восстановлением |
| 2 | ResultModal.jsx:180-221 (prefill результата) | — | ✓ `в темпе` | — | — | ✓ км+темп, **без** скобочного | ✓ `[×x]` | — | ✓ только `пауза` | ✓ (≡ #1) |
| 3 | ResultModal.jsx:294-322 (loadDayPlan, простой бег) | ✓ | ✓ `темп:`/`M:SS /км` | — | — | — | — | — | — | — |
| 4 | DaySheetV3 parseRunStructure (Calendar/v3/DaySheetV3.jsx:21-33) | — | — | — | — | ✓ км+темп `в темпе` | — | — | — | — |
| 5 | calV3 parsePlanMetrics (Calendar/v3/calV3.js:58-73) | ✓ + защита от `5:30 км`, `километр` | ✓ `/км`/`темп:`/`@5:30` | — | — | — | — | — | — | — |
| 6 | calV3 buildRunSegments (calV3.js:87-159) | через параметр | через параметр | — | — | ✓ `размин\w*` (толерантнее) + дефолты 2 км | ✓ `[×xх]` — **единственная знает кириллич. х**, lookahead `(?![а-яёa-z])` | ✓ мин/сек/с | ✓ `восст\|пауз\|отдых\|трусц`, м/км/мин/сек | частично (через reps) |
| 7 | TodayHeroV3 parseDescription (Dashboard/v3/TodayHeroV3.jsx:273-322) | ✗ **БАГ B**: `4×1 км` → km=1 | ✓ только `мин/км \| /км` | ✓ `H:MM:SS` | — | — | ✓ `(к?м)` | — | — | — |
| 8 | NextWorkoutSectionV3 parseDescription (:119-156) | ✓ | ✓ только `мин/км \| /км` | ✓ `H:MM:SS` | ✓ `ЧСС/пульс/зона N` — **уникально** | — | ✗ **БАГ A** (`\b`) | — | — | — |
| 9 | WeekSectionV3 extractKm/extractPace/extractIntervals (:202-231) | ✓ | ✓ `/км`/`темп:` | — | — | — | ✗ **БАГ A** (`\b`) | — | — | — |

Выводы:
- Ни одна копия не покрывает всё; «4×1 км» в одном и том же описании дня даёт три разных результата
  в TodayHero (км=1), NextWorkout (интервалы не распознаны) и календаре (распознано корректно).
- Канонической предлагается пара в `src/utils/workoutDescription.js`:
  `parseWorkoutDescription(text)` на базе объединения #5+#7+#8 (км с `(?:^|\s)`-префиксом из #8,
  темп из #5 — три паттерна, время/intervals из #7 с юнит-паттерном `(км|м)(?![а-яёa-z])` из #6,
  hrZone из #8, разминка/заминка из #4) и `parseRunBuilderFields(text)` для prefill конструкторов
  (#1 как самая полная, ResultModal переводится на неё).
- `buildRunSegments` (#6) остаётся отдельной (строит сегменты, а не извлекает поля), но юнит-регэкспы
  должны импортироваться из общего модуля.
- ~120-150 строк экономии + три исправленных пользовательских бага.

### 3.2. Форматтеры темпа — 6 живых (+2 мёртвых) реализаций

| Реализация | Вход | Округление | Краевой случай |
|---|---|---|---|
| `Stats/StatsUtils.js:66 formatPace` | сек/км | **нет** | float → «5:30.5» (проверено в node); 0 → «—», отрицательные не отфильтрованы |
| `Stats/CombinedWorkoutChart.jsx:22 formatPaceFromSeconds` | сек/км | `Math.round` до разбиения — корректно | `Number.isFinite`-guard — лучшая версия |
| `Stats/v3/blocks.jsx:18 fmtPaceSec` | сек/км | нет | тот же float-риск, что StatsUtils |
| `Dashboard/GoalCountdownWidget.jsx:81 formatPaceFromSec` | сек/км | округляет **секунды отдельно** | **БАГ: 299.7 → «4:60»** (проверено в node) |
| `utils/workoutFormUtils.js:53 formatPace` | мин/км (float) | секунды отдельно | тот же «4:60»-класс бага (5.999 мин → «5:60») |
| `utils/lapFormat.js:52 formatLapPace` | lap-объект | `Math.round` до разбиения — корректно | живой канон для кругов |
| мёртвые: PaceChart.jsx:10, PersonalRecordsWidget.jsx:29 | — | — | решается удалением |

Канон: `src/utils/formatters.js#formatPaceSec(sec)` по образцу CombinedWorkoutChart (finite-guard +
round-до-разбиения); `workoutFormUtils.formatPace` остаётся для мин/км, но с фиксом переноса 60 сек.
~30 строк экономии + 2 исправленных краевых бага.

### 3.3. Форматтеры длительности — 6 живых реализаций

| Реализация | Формат | Отличия |
|---|---|---|
| `utils/lapFormat.js:4 formatLapDuration` | `H:MM:SS` / `M:SS` | Math.round, null при ≤0 — кандидат в канон |
| `utils/lapFormat.js:17 formatWorkoutDuration` | то же + ветка минут `«1ч 5м»` | принимает workout-объект |
| `Stats/v3/blocks.jsx:481 fmtTime` | `H:MM:SS` / `M:SS` | ≡ lapFormat.formatLapDuration без round |
| `screens/profile/ProfileV3.jsx:39 fmtTime` | `H:MM:SS` / `M:SS` | ≡ blocks.fmtTime (точная копия, до Number-каста) |
| `Dashboard/v3/PRSectionV3.jsx:112 formatTime` | то же + фолбэк на сырое `result_time` | тонкая обёртка |
| `utils/workoutFormUtils.js:27 formatTime` | всегда `H:MM:SS` (с нулём часов) | формат для инпутов, не для дисплея |
| `Calendar/ResultModal.jsx:224 formatDuration` | `«1 мин 30 сек»` | словесный формат — отдельный кейс |
| мёртвая: PersonalRecordsWidget.jsx:20 | — | решается удалением |

Канон: `lapFormat.formatLapDuration` уже вынесен «дословно из WorkoutDetailsModal» — расширить до
`src/utils/formatters.js` и перевести blocks/ProfileV3/PRSectionV3. ~25 строк.

### 3.4. Относительное время — 5 реализаций

| Реализация | Гранулярность | Отличия |
|---|---|---|
| `Coach/AthleteOverlay.jsx:449 formatRelative` | сек→мин→ч→дн | базовая |
| `Coach/EventQuickReplySheet.jsx:27 formatTime` | сек→мин→ч→дн | **байт-в-байт ≡ AthleteOverlay** |
| `Coach/EventStream.jsx:24 formatRelativeTime` | + порог 7 дн → дата | форк с улучшением, не бэкпортирован в 2 копии выше |
| `Coach/AthleteTable.jsx:60 formatLastActivity` / `AthleteGrid.jsx:160 formatLastActivityShort` | дни (через `coachHelpers.daysSince`) | «Сегодня/Вчера/N нед. назад» — другая шкала |
| `common/NotificationCenter.jsx:33 formatTimeAgo` | мин→ч→вчера→дн→дата | + плюрализация — самая полная |
| `Dashboard/v3/TodayHeroV3.jsx:332 formatTime(iso)` | — | это часы:минуты, не относительное — не дубль |

Канон: `src/utils/relativeTime.js#formatRelativeTime(iso, { dateAfterDays = 7 })` на базе
EventStream/NotificationCenter; формат «Сегодня/Вчера» оставить как отдельную функцию там же. ~35 строк.

### 3.5. Загрузка статистики профиля (normPlan `data ?? res`)

`UserProfileScreen.jsx:139-176 loadProfileStats` — живой; ≡ мёртвым `DashboardStatsWidget.jsx:25-48` и
`ProfileQuickMetricsWidget.jsx:24-43` (clone-группы x3). После удаления мёртвых остаётся одна живая
копия — рефакторинг не нужен, но паттерн нормализации `const raw = res?.data ?? res` повторяется по
src/ десятки раз инлайном (ApiClient мог бы нормализовывать сам).

### 3.6. Синхронизация провайдеров

`settings/useSettingsActions.js:25 runStravaSync` / `:42 runHuaweiSync` ↔ `SettingsScreen.jsx:1376 syncProvider`.
Не точные копии: syncProvider — generic по id (и делегирует huawei в runHuaweiSync), runStravaSync —
пост-OAuth-вариант с другим текстом и таймаутом (4000 vs 3000 мс). Риск рассинхрона низкий, но
runStravaSync можно свести к `syncProvider('strava', { announceConnected: true })` при следующем рефакторинге.

### 3.7. Чарты: CombinedWorkoutChart ↔ PaceChart/HeartRateChart

7 функций дословно (`formatPaceFromSeconds`, `formatTime`, `clamp`, `percentile`, `getPaceDomain`,
smoothing, тики — clone-группы 26/26/20/17/13 строк). PaceChart/HeartRateChart мёртвые (цепочка C),
живой только CombinedWorkoutChart → после удаления мёртвых дубль исчезает полностью, выносить ничего
не надо.

### 3.8. DayModal ↔ DaySheetV3

`Calendar/DayModal.jsx` (594 строки, жив через UserProfileScreen) и `Calendar/v3/DaySheetV3.jsx`
(376 строк, живой календарь) — две параллельные «поверхности дня» с собственными загрузками getDay,
парсингом и рендером. Не построчный дубль (общих clone-групп нет), а функциональный: одна и та же
фича реализована дважды. Рекомендация: перевести UserProfileScreen на DaySheetV3 (`embedded`-режим уже
поддерживается) и убить DayModal — это снимет и дубль `stripHtml` (DayModal:23 ≈ calV3:76, отличие:
calV3 схлопывает пробелы → одинаково), и третью копию ExerciseList-вёрстки.

---

## 4. Осознанные/допустимые дубли

- **LeafletRouteMap ↔ MapboxRouteMap** (`src/components/Stats/`) — намеренная drop-in пара, выбор по
  `VITE_MAPBOX_TOKEN`. Подтверждено расхождение: hover-маркер синхронизации с графиком
  (`hoverIndex`, MapboxRouteMap.jsx:32,138-150) есть только в Mapbox-версии — Leaflet-fallback теряет
  фичу молча. Оставить как есть, но зафиксировать паритет фич в комментарии или довнести hover в Leaflet.
- **WorkoutCard ↔ WorkoutDetailsModal ExerciseList** — копия задокументирована в коде («те же классы,
  чтобы выглядели одинаково»), т.е. осознанная; тем не менее это JSX, а не CSS — перенос в общий
  компонент безопасен (см. раздел 2).
- **`common/Modal.jsx` vs локальные Escape-эффекты** — частично осознанно (кастомные шторки не хотят
  обвязку Modal), но вариант с несохранением prev-overflow в самом Modal — расхождение не в пользу канона.
- **ChatHeaderMenu vs PlanActionsMenuV3** — оба «kebab-меню», но рендер и API различаются (items-массив
  vs фиксированные пропсы); дублем является только dismiss-эффект (см. раздел 2), сами компоненты
  сливать не стоит.

## 5. Решается удалением мёртвого кода

Все ссылки — на `DEAD-CODE-FRONTEND.md` (секция 1). Clone-группы, где **все** копии мёртвые (16 групп):

- PaceChart ↔ HeartRateChart — 10 групп (строки 87-116/104-131/178-203/194-216/227-244/275-293/296-307/323-346/338-348/358-368 и зеркальные) — цепочка C;
- ActivityHeatmap:313-331 ↔ DistanceChart:143-162 — цепочка C;
- DashboardStatsWidget:25-44 ↔ ProfileQuickMetricsWidget:24-43 — цепочка A;
- RacePredictionWidget:75-90 ↔ TrainingLoadWidget:132-146 — цепочка A;
- RacePredictionWidget:153-163 ↔ :311-321 (внутренний) — цепочка A;
- WorkoutShareCard:710-720 ↔ :924-934 (внутренний) — цепочка C;
- семантические: TrainingLoadWidget≈FormSectionV3, TrendComparisonWidget≈TrendsSmallV3,
  DashboardStatsWidget.loadStats≈ProfileQuickMetricsWidget.loadStats, PersonalRecordsWidget-форматтеры,
  RacePredictionWidget.DISTANCE_LABELS_FULL.

Частично мёртвые (после удаления мёртвой стороны дубль исчезает/сокращается, 8 групп):

- CombinedWorkoutChart ↔ PaceChart (5 групп) → остаётся только живой CombinedWorkoutChart;
- chatApi.js:91-104 ↔ chatStream.worker.js:35-46 (NDJSON-ридер) → воркер мёртв (цепочка D), остаётся chatApi;
- DashboardStatsWidget:36-48 ↔ UserProfileScreen:160-172 и тройная группа с ProfileQuickMetricsWidget → остаётся живой UserProfileScreen;
- isAiPlanMode x3 → после удаления Dashboard.jsx остаётся 2 живые копии (раздел 2).

## 6. Ложные срабатывания

- **CalendarScreen.jsx:688-698 ↔ 715-725** — не «скопированная логика», а один и тот же JSX-проп,
  передаваемый в две взаимоисключающие ветки (WeekViewV3/MonthViewV3). Дубль формальный; лечится
  переменной, но рисков рассинхрона почти нет (правки в одном месте вызовут визуально очевидную разницу).
- **UserProfileScreen.jsx:385-394 ↔ 417-426** — две одинаковые loading-ветки рендера; тривиально.
- **ChatScreen.jsx:813-827 ↔ 866-880** — кнопка «Прочитать все» в двух ветках одного тернарника
  (sidebar админа/юзера); формальный JSX-дубль уровня разметки.
- **TodayHeroV3.formatTime(iso) vs остальные formatTime** — разные функции (часы:минуты из ISO vs
  длительность из секунд), совпадает только имя.
- **GoalCountdownWidget.formatPace** — несмотря на имя, это passthrough-строка (`String(pace).trim()`),
  не дубль форматтеров.
- **`coachHelpers.daysSince`-обёртки** (formatLastActivity/Short) vs `daysToRace` — разные направления
  времени (прошлое/будущее), не сливать с 1.2, хотя жить им место в одном utils-файле.

---

## Сводка

- **Проверено**: 39 clone-групп (из 134 в clones.txt) + 20 семантических кандидатов из dup_candidates.md ≈ **59 позиций**.
- **Критичных** (уже разошлись/риск рассинхрона): **9** (раздел 1), из них с подтверждёнными
  пользовательскими багами — **4**: сломанные интервалы в WeekSectionV3/NextWorkoutSectionV3 (`\b`),
  km=1 для «4×1 км» в TodayHeroV3, «4:60» в GoalCountdownWidget, пропавший `triggerRefresh` при
  удалении тренировки из профиля.
- **Решается удалением мёртвого кода**: 16 clone-групп целиком + 8 частично (см. DEAD-CODE-FRONTEND.md).
- **Потенциальная экономия в живом коде**: ~600-700 строк (разделы 2-3: ~370 в таблице раздела 2,
  ~150 — унификация парсинга описаний, ~90 — форматтеры темпа/времени/отн. времени), не считая
  ~10 300 строк мёртвого кода из DEAD-CODE-FRONTEND.md.
- Первоочередные шаги: (1) общий `workoutDescription.js` + фикс `\b`-регэкспов, (2) хук
  `useModalDismiss`, (3) `cryptoUtils.js` для PIN-сервисов, (4) словари
  (DISTANCE_LABELS/SPEC_LABELS/TYPE_LABELS/daysToRace) в `src/utils/`.
