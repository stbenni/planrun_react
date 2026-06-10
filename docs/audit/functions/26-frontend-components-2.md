# Frontend components 2/6 (Calendar ч.2, chat, Coach, common ч.1) — справочник

## `src/components/Calendar/v3/PlanActionsMenuV3.jsx` (70 строк)
Kebab-меню «три точки» с действиями плана в шапке календаря v3: «Пересчитать план» / «Новый план» (если план завершён) и «Очистить план». Самодостаточный: своё open-состояние, закрытие по клику вне и Escape.

### `PlanActionsMenuV3({isPlanCompleted, recalculating, generatingNext, clearingPlan, onPrimary, onClear, copyWeekSlot})` — L6
Default-export компонент. Рендерит кнопку `MoreHorizontal` (lucide) и выпадающую панель `role="menu"` с опциональным слотом `copyWeekSlot` (туда WeekViewV3 вкладывает CopyWeekV3), основным действием (Sparkles/RefreshCw + текст по состоянию) и danger-кнопкой очистки. useEffect навешивает pointerdown/keydown-листенеры на document для закрытия. Сторов/API не использует — все действия через колбэки. Потребитель: CalendarScreen.

## `src/components/Calendar/v3/useIsMobile.js` (18 строк)
Хук определения мобильного вьюпорта для адаптивных v3-видов календаря.

### `useIsMobile()` — L6
Default-export хук. Возвращает boolean по `matchMedia('(max-width: 640px)')`, подписывается на `change` медиа-запроса; SSR-safe (false без window). Потребители: WeekViewV3, MonthViewV3.

`MOBILE_Q` — L4 — строка медиа-запроса (не экспортируется).

## `src/components/Calendar/v3/useSheetFocus.js` (33 строки)
Хук доступности для v3-шторок/модалок: автофокус внутрь, focus-trap по Tab, возврат фокуса при закрытии.

### `useSheetFocus(ref, active)` — L7
Default-export хук. При `active` фокусирует первый фокусируемый элемент внутри `ref.current`, перехватывает Tab/Shift+Tab для циклического обхода (selector L5: button/href/input/select/textarea/tabindex), на cleanup возвращает фокус предыдущему `document.activeElement`. Потребители: DaySheetV3, AddTrainingModal, ResultModal.

`SELECTOR` — L5 — CSS-селектор фокусируемых элементов (не экспортируется).

## `src/components/Calendar/v3/VolumeRailV3.jsx` (41 строка)
Визуализация объёма по неделям (порт VolumeRail из v3-прототипа): мобайл — тонкие горизонтальные бары, десктоп — вертикальные колонки в side-rail.

### `VolumeRailV3({items, max, horizontal})` — L7
Default-export компонент. `items: [{n, vol, phase, current}]`, `max` — максимум для нормировки. Для каждой недели рендерит имя («Нед N»/«НN»), бар с шириной/высотой `vol/max` и цветом фазы из `PHASES` (calV3), текущая неделя — полная непрозрачность. Возвращает null при пустых items. Потребители: WeekViewV3 (desktop side-rail), MonthViewV3.

## `src/components/Calendar/v3/WeekNotesV3.jsx` (114 строк)
Заметки к неделе в glass-стиле (порт из WeekCalendar). CRUD через переданный api-клиент; права на редактирование/удаление: автор, тренер, админ.

### `WeekNotesV3({api, weekStartDate, viewContext, canEdit})` — L7
Default-export компонент. Стор: `useAuthStore` (user — для прав canManage). API: `api.getWeekNotes`, `api.saveWeekNote` (create/update по id), `api.deleteWeekNote` — все с `viewContext` (просмотр атлета тренером). Локальный стейт: notes, draft, editId/editText, saving. Рендерит карточку `calv3-card` со списком заметок (текст + автор через `getDisplayName`, кнопки ✎/✕ при canManage), inline-редактор (textarea, maxLength 2000) и форму добавления. Если заметок нет и `!canEdit` — не рендерится. Значимые хендлеры: `load` (L21, загрузка с silent-catch), `save` (L31), `update` (L43), `remove` (L55, с window.confirm).

## `src/components/Calendar/v3/WeekViewV3.jsx` (305 строк)
Недельный вид календаря v3 (порт CalWeekMobile/CalWeekDesktop). Мобайл: список DayRow + bottom-sheet DaySheetV3; десктоп: 7 карточек DayCard + встроенный sheet + side-rail (объём, фазы). Данные — через утилиты calV3.

### `DayRow({day, onClick})` — L21
Внутренний компонент строки дня (мобайл): дата (dow/d), цветовой акцент по `typeColorVar(day.type)`, заголовок, км + темп (факт или ≈suggested), индикаторы «ключевая»/✓done/шеврон. Не экспортируется.

### `DayCard({day, active, onClick})` — L49
Внутренний компонент карточки дня (десктоп-грид): верх — dow/число, цветовая полоска типа, заголовок + метрика, футер «✓ Выполнено» и тег «КЛЮЧ». Не экспортируется.

### `WeekViewV3({plan, data, canEdit, viewMode, onViewMode, lockView, hideSeg, initialDate, initialDateKey, api, viewContext, onEditDay, onMarkDone, onEditResult, onDeleteResult, onAddTraining, onTrainingChanged, planMenu})` — L74
Default-export компонент. Хуки/сторы: `useIsMobile`, `useWorkoutRefreshStore` (version для инвалидации префетча), `prefetchDays` из dayCache (фоновый прогрев дней недели). Утилиты calV3: `getWeekForStartDate`, `buildWeekDays`, `buildDayModel`, `deriveCurrentPhase`, `buildVolumeWindow`, `formatWeekRange` и др. Стейт: weekStart, selectedDate, sheetDayDate (мобильная шторка хранит дату, модель выводится из свежих days). Снап недели только при навигации с дашборда (initialDate/initialDateKey), с одноразовым открытием шторки (navHandledRef). Считает totalKm/doneKm/subtitle, признак `planEnded` (неделя после конца плана). Мобайл: CalHeaderV3 (с planMenu, в который клонированием подсаживается CopyWeekV3 как menuitem), список DayRow, WeekNotesV3, DaySheetV3-шторка. Десктоп: навигация ‹/›, CopyWeekV3, «+ Тренировка», PhaseRibbonV3, грид DayCard, embedded DaySheetV3, aside с VolumeRailV3 (+заметка о пике объёма), PhasesListV3, WeekNotesV3. Значимые хендлеры: swipe-эффект (L150, touch-листенеры для перелистывания недель), `goPrev`/`goNext` (L146-147), `sheetCallbacks` (L181, прокидывание колбэков в DaySheetV3 с закрытием шторки перед onAddTraining).

## `src/components/Calendar/WeekCalendarIcons.jsx` (12 строк)
Re-export иконок из `common/Icons` под алиасами для ячеек недели.

### Re-export — L6
`RunningIcon→RunIcon`, `OtherIcon→OFPIcon`, `SbuIcon`, `RestIcon`, `CompletedIcon`. Единственный потребитель — DashboardWeekStrip (импортирует RunIcon, OFPIcon, SbuIcon, CompletedIcon; **RestIcon через этот модуль никто не использует**).

## `src/components/Calendar/Week.jsx` (122 строки)
Компонент недели тренировок старого календаря (calendar_v2): сворачиваемая шапка недели + грид из 7 компонентов Day. **Единственный потребитель — Calendar.jsx, который сам нигде не импортируется (мёртвая цепочка, заменена WeekViewV3/v3).**

### `Week({week, isCurrentWeek, progressData, workoutsData, resultsData, onDayPress, canEdit, isOwner, onDeleteWeek})` — L12
Default-export компонент. Стейт: isExpanded (по умолчанию = isCurrentWeek). Рендерит шапку (toggle ▼/▶, «Неделя N (даты)», total_volume/key_session, inline-стилизованная кнопка удаления недели с confirm для canEdit&&isOwner) и контент: заголовки Пн-Вс + 7 `<Day>` с прокинутыми данными. Хендлер `handleDeleteWeek` (L24) — confirm + onDeleteWeek(week.number). Сторов/API нет.

## `src/components/Calendar/WorkoutCard.jsx` (452 строки)
Карточка тренировки в стиле Strava/NRC: крупные метрики, цветовая полоска по типу, список тренировок дня (planDays) с кнопками изменить/удалить, упражнения, результаты. Потребители: DayModal, Dashboard.

### `getWorkoutStripColorClass(type)` — L13
Возвращает CSS-суффикс цветовой группы полоски по типу плана (easy/tempo/interval/long/control/race/sbu/rest...) и activity_type импорта (walking/hiking); fallback 'run', для 'free' — null. Не экспортируется.

### `stripRedundantTypePrefix(description, type)` — L39
Убирает дублирующий тип в начале описания (например «ОФП: ...» если тип уже в заголовке), сравнение по uppercase-префиксу. Не экспортируется.

### `limitDescription(description, maxItems)` — L52
Ограничивает HTML-описание до maxItems пунктов: парсит через DOM (`<li>` или строки), возвращает `{html, hasMore}`. Не экспортируется.

### `WorkoutCard({workout, date, status, onPress, isToday, compact, dayDetail, workoutMetrics, results, planDays, onDeletePlanDay, onEditPlanDay, canEdit, extraActions, maxDescriptionItems})` — L73
Default-export компонент. Без сторов; утилиты: `parseStructuredExercises`, `WORKOUT_TYPE_LABEL`. Конфиг статусов (completed/planned/missed/rest) с иконками и цветами. useMemo `metrics` (L166): приоритет workoutMetrics (getAllWorkoutsSummary, duration в минутах / duration_seconds) → results[0] (workout_log) → regex-извлечение из текста. useMemo `workoutTitle` (L198). Рендерит: дата + бейдж «Сегодня»; блоки planDays с цветовой полоской, кнопками изменить/удалить (canEdit), структурированными упражнениями (other/sbu через parseStructuredExercises, чипы sets×reps/вес) либо HTML-описанием с лимитом; для одиночного workout — метрики (дистанция/время/темп), описание, dayExercises (до 5 + «ещё»), множественные результаты; rest-плашка; блок actions (extraActions либо кнопки «Детали»/«Отметить выполненной»). Значимые внутренние функции: `formatDate` (L118, UTC день недели), `extractMetrics` (L128, regex км/мин/темп), `formatDurationDisplay` (L155, мин/сек → «1ч 2м»).

## `src/components/chat/CapabilitiesBanner.jsx` (19 строк)
Баннер над AI-диалогом: показывает, что AI умеет менять план (write-инструменты).

### `CapabilitiesBanner()` — L8
Default-export компонент без пропсов. Рендерит заголовок «★ AI МОЖЕТ ИЗМЕНЯТЬ ТВОЙ ПЛАН» и чипы из const `CHIPS` (L6: править/переносить/отмечать/пересчитать). Потребитель: ChatScreen (только в AI-чате).

## `src/components/chat/ChatHeaderMenu.jsx` (66 строк)
⋯-меню в шапке чата с пунктами действий (например «Очистить чат»). Закрывается по клику снаружи и Escape.

### `ChatHeaderMenu({items})` — L9
Default-export компонент. `items: [{key, label, onClick, disabled, tone}]` — falsy-элементы фильтруются, при пустом списке возвращает null. Рендерит кнопку с inline-SVG (три точки) и панель `role="menu"`; пункт с `tone==='danger'` получает danger-класс. Закрытие: mousedown вне wrapRef + Escape. Потребитель: ChatScreen.

## `src/components/chat/ToolResultCard.jsx` (42 строки)
Зелёная карточка-результат под сообщением AI, когда сработал write-инструмент (правка плана). Данные — `metadata.tools_used` сообщения или список, собранный при стриме.

### `ToolResultCard({tools, onOpen})` — L19
Default-export компонент. Фильтрует tools по словарю `WRITE_TOOL_LABELS` (L7: update/add/delete_training_day, swap/move/copy_day, log_workout, recalculate_plan, generate_next_plan); null если write-инструментов нет. Заголовок — лейбл единственного инструмента либо «План обновлён»; для async (recalculate_plan/generate_next_plan) — подсказка «Обнови календарь через 3-5 минут». Опциональная кнопка «Открыть» (onOpen). Потребитель: ChatScreen.

## `src/components/Coach/AthleteGrid.jsx` (167 строк)
View 'grid' в CoachWorkspace: heatmap-тайлы compliance атлетов — полоса выполнения, аватар/имя/цель, weekDone/weekTotal, VDOT, sparkline, бейджи РИСК / Сегодня / дни до гонки, чекбокс для bulk-выбора.

### `shortName(athlete)` — L21
«Имя Ф.» из name/username. Не экспортируется.

### `daysToRace(iso)` — L29
Дни до даты гонки (null если прошла/невалидна). Не экспортируется; дублируется в AthleteOverlay/AthleteTable/CompareAthletesPanel.

### `complianceColor(pct)` — L37
Цвет-токен по доле выполнения: ≥0.8 success, ≥0.5 warning, >0 danger, иначе gray. Не экспортируется.

### `AthleteGrid({athletes, activeId, onOpenAthlete, selected, onToggleSelected})` — L45
Default-export компонент. Использует `CoachAvatar`/`Sparkline` (CoachPrimitives) и `coachHelpers.isAtRisk/hasFreshUpload` (useCoachStore). Для каждого атлета рендерит тайл `role="button"` (клик/Enter/Space → onOpenAthlete): strip заполнения compliance, голова с аватаром (ring danger/success), чекбокс выбора (stopPropagation), метрики done/total + VDOT, Sparkline по volume_spark (либо заглушка), бейджи риска/свежей загрузки/последней активности, дни до гонки если ≤60. Пустой список — плейсхолдер. Потребитель: CoachWorkspace.

### `formatLastActivityShort(iso)` — L160
Короткий относительный формат последней активности через `coachHelpers.daysSince` («Сегодня»/«Вчера»/«N дн назад»/«N нед назад»). Не экспортируется.

## `src/components/Coach/AthleteOverlay.jsx` (480 строк)
Slide-in панель деталей атлета (drill-in) поверх CoachWorkspace. Табы: Обзор (метрики + сегодняшний план + объём 7д), План недели, Графики, Чат. Закрытие: ✕, scrim, Esc. Рендер через portal в #modal-root.

### `formatGoal(a)` — L29
«Дистанция · цель время» из race_distance/race_target_time. Не экспортируется.

### `daysToRace(iso)` — L36
Дни до гонки (min 0). Не экспортируется (4-я копия по Coach-файлам).

### `sparkDateLabels(n)` — L44
Массив подписей дат для sparkline за n дней (ru-RU, до сегодня включительно). Не экспортируется.

### `AthleteOverlay({athlete, onClose})` — L55
Default-export компонент. Сторы/хуки: `useAuthStore` (api), `useCoachStore` (openBulkAssign, selectMany), `useNavigate`. API: `api.getAthleteDetails(athlete.id)` при открытии (стейт details/loading/error с cancelled-guard). Считает atRisk/fresh (coachHelpers), compliance %, volume7d, дни до гонки. Эффект L113: Escape-закрытие + лок body overflow. Рендерит: scrim + aside-диалог с шапкой (CoachAvatar c ring, GroupTag, цель), 4 action-кнопки (Чат → navigate('/chat', {state.contactUser}); Править план / Перенести → navigate(`/calendar?athlete=slug`); Шаблон → selectMany + openBulkAssign), табы, тело по активному табу (Metric×4 + «Сегодня по плану» с цветом WORKOUT_TYPE_COLOR + объём со Sparkline; либо WeekPlanTab/ChartsTab/ChatTab). Портал в #modal-root. Значимые хендлеры: handleOpenChat (L86), handleApplyTemplate (L98).

### `WeekPlanTab({loading, error, weekStart, days, onEditPlan})` — L283
Внутренний таб «План недели»: 7 строк дней с цветной полоской типа, лейблом, ★ ключевой, первой строкой описания и факт-метрикой (`distance_done/pace_done` или ✓); кнопка «В календарь». Не экспортируется.

### `ChartsTab({loading, error, volumeWeeks, vdotHistory})` — L340
Внутренний таб «Графики»: карточки «Объём · 8 недель» (сумма км + Sparkline с подписями недель) и «VDOT · динамика» (текущий + Sparkline); плейсхолдеры при <2 точках. Не экспортируется.

### `ChatTab({loading, error, notes, athleteName, onOpenChat})` — L392
Внутренний таб «Чат»: CTA «Открыть полный чат» + список последних заметок к тренировкам (автор Вы/атлет, дата + относительное время). Не экспортируется.

### `firstLine(text)` — L428
Первая непустая строка текста. Не экспортируется (дубль AthleteTable.firstLine).

### `formatShortDate(iso)` — L434
«7 июн.» (ru-RU day+month). Не экспортируется.

### `formatWeekRange(startIso)` — L441
«1 июн. – 7 июн.» (старт + 6 дней). Не экспортируется.

### `formatRelative(iso)` — L449
Относительное время («только что»/«N мин/ч/дн назад»). Не экспортируется (дубль formatTime/formatRelativeTime в EventQuickReplySheet/EventStream).

### `Metric({label, value, suffix, color, delta})` — L460
Внутренняя KPI-ячейка обзора: лейбл + значение (+suffix, +delta со стрелочной окраской по знаку). Не экспортируется.

## `src/components/Coach/AthleteTable.jsx` (278 строк)
Табличное представление атлетов в CoachWorkspace: колонки Атлет / Цель / До гонки / Неделя / 7 дней объём / Сегодня по плану / Активность / VDOT, чекбоксы с Shift+Click range-select.

### `formatGoal(a)` — L32
Лейбл цели из DISTANCE_LABELS (L14) или GOAL_LABELS (L25). Не экспортируется.

### `formatRaceDateShort(iso)` — L38
«7 июн.» из даты гонки. Не экспортируется.

### `firstLine(text)` — L46
Первая непустая строка (для предпросмотра описания тренировки). Не экспортируется.

### `daysToRace(iso)` — L52
Дни до гонки (null если прошла). Не экспортируется.

### `formatLastActivity(iso)` — L60
Относительный формат активности через `coachHelpers.daysSince`. Не экспортируется.

### `AthleteTable({athletes, activeId, onOpenAthlete, selected, onToggleSelected, onSelectMany})` — L70
Default-export компонент. Использует CoachAvatar/ComplianceBar/GroupTag/Sparkline/WORKOUT_TYPE_COLOR (CoachPrimitives) и coachHelpers. Head-чекбокс «выбрать всех» с indeterminate; хендлер `handleCheckboxClick` (L93) — Shift+Click range-select по якорю lastClickedIdRef через onSelectMany. Каждая строка `role="button"` (клик/Enter/Space → onOpenAthlete): аватар с ring + непрочитанные, GroupTag, цель + target time, дни до гонки (подсветка ≤30), ComplianceBar done/total, Sparkline + сумма км, «сегодня по плану» (цветная точка типа + дистанция/темп либо firstLine описания), активность + тег «новая», VDOT + тренд по знаку. Потребитель: CoachWorkspace.

## `src/components/Coach/BulkActionBar.jsx` (69 строк)
Нижняя плавающая плашка действий над выбранными атлетами (selected.size > 0), portal в #modal-root.

### `BulkActionBar({athletes, selected, onClear, onAssign, onCompare, onApplyTemplate, onSendMessage})` — L13
Default-export компонент. Показывает счётчик «Выбрано · N» + до 3 первых имён (+ «ещё M»), кнопки: Назначить тренировку, Сравнить (только при 2-4 выбранных), Применить шаблон, Сообщение группе, ✕ очистить выбор. Все действия — колбэки; сторов нет. Потребитель: CoachWorkspace.

## `src/components/Coach/BulkAssignModal.jsx` (366 строк)
Мастер «Назначить тренировку» из 3 шагов: шаблон → атлеты (чипы групп, «все», «очистить») → дата + сводка. Если атлеты предвыбраны (initialSelected) — шаг 2 пропускается.

### `dateForOffset(offset)` — L24
Date на полночь сегодня+offset дней. Не экспортируется.

### `formatIsoDate(d)` — L31
YYYY-MM-DD из Date. Не экспортируется.

### `formatHumanDate(d)` — L38
«10 июня, вт» (ru-RU). Не экспортируется.

### `BulkAssignModal({isOpen, onClose, athletes, groups, templates, initialSelected, busy, onConfirm})` — L42
Default-export компонент. Стейт: step, templateId, selectedIds (Set), datePreset ('today'/'tomorrow'/'day_after'/'custom' из DATE_PRESETS L18), customDate. Сброс при открытии (L59). Escape + body-lock (L71). useMemo: template, selectedAthletes, finalDate. Шаг 1: список шаблонов с иконкой `getTemplateIcon` и uses_count (либо empty-подсказка); шаг 2: чипы «+ вся группа …»/«+ Все»/«Очистить» + чек-список атлетов с CoachAvatar; шаг 3: сводка шаблона (цвет WORKOUT_TYPE_COLOR), дата-чипы + input type=date, pills выбранных. Футер: Назад / Дальше / «✓ Назначить · N атлетам» → `onConfirm({template_id, athlete_ids, date})`. Портал в #modal-root. Значимые хендлеры: goNext/goBack с пропуском шага 2 (L105/113), toggle (L121), addGroup (L129). Потребители: CoachWorkspace, CoachGroupsView.

## `src/components/Coach/CoachPrimitives.jsx` (217 строк)
Мелкие переиспользуемые примитивы тренерского workspace: Sparkline, ComplianceBar, GroupTag, CoachAvatar, getInitials, TONE. Все цвета — из токенов.

### `Sparkline({data, w, h, color, bg, thick, labels, unit})` — L16
Экспортируемый компонент. SVG-линия по точкам (нормировка на max), площадь-заливка 0.12 opacity, точка на последнем значении. При `labels` той же длины — интерактивный режим: hover-зоны rect, dashed-вертикаль, тултип «дата + значение unit» (абсолютно позиционированный). При <2 точек — «—». Потребители: AthleteGrid/Table/Overlay, CompareAthletesPanel, CoachWorkspace, NextWorkoutSectionV3 и др.

### `ComplianceBar({done, total, w})` — L114
Экспортируемый компонент. Progressbar 4px: цвет по доле (≥0.8 success / ≥0.5 warning / >0 danger / gray), aria-valuenow.

### `GroupTag({group, size})` — L134
Экспортируемый компонент. Pill-чип группы атлета `{id, name, color}` с точкой и фоном `color+'15'`; null без имени.

### `getInitials(athlete)` — L161
Экспортируемая функция. Инициалы из name (две первых буквы слов) либо username (2 символа), fallback '?'.

### `CoachAvatar({athlete, size, ring, apiBaseUrl, radius})` — L172
Экспортируемый компонент. `<img>` через `getAvatarSrc(avatar_path)` (вариант sm при size≤32) либо div с инициалами на тоне `_tone`; ring — двойной box-shadow рамки (danger/success-сигналы).

### `TONE` — L209
Экспортируемый const: тон-стили {bg, color, solid} для primary/success/warning/danger/info (KPI-карточки и события).

### `WORKOUT_TYPE_COLOR` — L217
Re-export из `utils/workoutTypes`.

## `src/components/Coach/CompareAthletesPanel.jsx` (163 строки)
Модальное сравнение 2-4 атлетов колонками: avatar/name/группа/цель + строки метрик (выполнение, объём 7д, VDOT, до гонки, последняя активность) + sparkline.

### `daysToRace(iso)` — L21
Дни до гонки. Не экспортируется (очередная копия).

### `CompareAthletesPanel({isOpen, athletes, onClose, onOpenAthlete})` — L29
Default-export компонент. Escape + body-lock (L30); null при <2 атлетов. Декларативный массив `rows` (L47) с render-функциями метрик (цвета по процентам, coachHelpers.daysSince для активности). Колонки grid по числу атлетов; шапка колонки — кнопка onOpenAthlete (CoachAvatar с ring риска/свежести, GroupTag, цель), затем метрики и Sparkline по volume_spark. Портал в #modal-root. Потребитель: CoachWorkspace.

## `src/components/Coach/ConfirmConflictDialog.jsx` (101 строка)
Диалог подтверждения перезаписи плана: показывается после preflight bulkAssign (overwrite=false), если сервер вернул conflicts[] — список «у кого что уже стоит» + кнопки Перезаписать/Отмена.

### `ConfirmConflictDialog({isOpen, conflicts, onClose, onConfirm, busy})` — L14
Default-export компонент. Escape (если не busy) + body-lock. Рендерит alertdialog: заголовок «У N атлетов уже есть план…», список конфликтов (имя + текущий тип из WORKOUT_TYPE_LABEL + truncated описание), футер Отмена / «Перезаписать» (busy → «Перезаписываю…»). Портал в #modal-root. Потребитель: CoachWorkspace.

### `plural(n)` — L90
Русское склонение «атлета/атлетов». Не экспортируется.

### `truncate(s, n)` — L98
Обрезка строки с «…». Не экспортируется.

## `src/components/Coach/EventQuickReplySheet.jsx` (171 строка)
Bottom-sheet быстрого ответа на событие (мобайл, вместо drill-in): шапка с аватаром, карточка события, quick-reply chips по типу события, textarea + отправка, 2×2 кнопки действий.

### `formatTime(iso)` — L27
Относительное время события. Не экспортируется (дубль formatRelativeTime).

### `EventQuickReplySheet({isOpen, event, onClose, onOpenAthlete, onSendMessage})` — L38
Default-export компонент. Стейт: text, busy, sentOk, errorMsg (сброс при открытии); Escape + body-lock. Чипы из `QUICK_REPLIES_BY_KIND` (L20: upload/risk/question/pr) дописываются в textarea. Хендлер `handleSend` (L74): await onSendMessage({athlete_id, text, event}), result===false → ошибка, успех → галочка + автозакрытие через 800 мс. Действия: «Открыть план»/«Графики» → onOpenAthlete + close; «Перенести»/«Черновик AI» — disabled («Скоро»). CoachAvatar c ring цвета TONE события. Портал в #modal-root. Потребитель: CoachWorkspace.

## `src/components/Coach/EventStream.jsx` (128 строк)
Лента событий тренера (view 'stream'): карточки из useCoachStore.events (API /coach_events) — аватар с ring тона, имя + время, иконка типа + заголовок + детали, CTA-кнопка.

### `formatRelativeTime(iso)` — L24
Относительное время, после 7 дней — дата «7 июн.». Не экспортируется.

### `EventStream({events, onOpenAthlete, onCta})` — L37
Default-export компонент. Эффект L42: сравнивает id событий с прошлым рендером (seenIdsRef), новые получают slide-in класс `--new` на 600 мс (на первый рендер не анимирует). Пустая лента — плейсхолдер «Пока тихо». Карточка-кнопка → onOpenAthlete(athlete_id); иконка по `KIND_ICON` (L16: upload/risk/warn/question/pr), цвета из TONE; вложенная CTA-кнопка (stopPropagation) → onCta(ev). Потребитель: CoachWorkspace.

## `src/components/Coach/GroupMessageDialog.jsx` (147 строк)
Диалог отправки одного сообщения всем выбранным атлетам (из BulkActionBar): textarea, параллельная отправка с прогрессом и подсчётом ошибок.

### `GroupMessageDialog({isOpen, athletes, selectedIds, onClose, onSend})` — L14
Default-export компонент. Стейт: text, busy, progress {done,total,errors}, completed; Escape (не во время busy) + body-lock. Хендлер `handleSend` (L44): Promise.all по выбранным, на каждом `onSend(a.id, msg)` с инкрементом прогресса/ошибок; при 0 ошибок — автозакрытие через 900 мс, иначе warn-итог. Рендер: шапка «N атлетов получат сообщение», аватары первых 8 (+«+M»), textarea, прогресс/результат, футер. Портал в #modal-root. Потребитель: CoachWorkspace (фактическая отправка — chatSendMessageToUser на стороне вызова).

## `src/components/Coach/TemplateEditorModal.jsx` (470 строк)
Редактор шаблона тренировки (create/edit): name/type/distance/иконка/description/is_key_workout + список упражнений (из exercise_library или вручную) с полями, перестановкой и удалением. Edit-режим — при initialTemplate (upsert через save_workout_template).

### `TemplateEditorModal({isOpen, onClose, initialTemplate, exerciseLibrary, busy, onSave})` — L50
Default-export компонент. Стейт всех полей + exercises[]; инициализация из initialTemplate (через normalizeExercise) или пустых значений при открытии (L68); Escape + body-lock (L89). Иконки — `TEMPLATE_ICON_OPTIONS` (toggle-кнопки), типы — TYPE_OPTIONS (L21, 12 типов). Хендлеры: addExercise/updateExercise/removeExercise/moveExercise (L103-123), `handleSave` (L125) — собирает payload (template_id для edit, числа через toIntOrNull/toFloatOrNull, order_index, фильтр пустых имён) → onSave. Портал в #modal-root. Потребитель: TemplatesScreen.

### `ExerciseRow({exercise, index, total, library, onChange, onRemove, onMoveUp, onMoveDown})` — L313
Внутренний компонент строки упражнения: номер, имя с datalist из библиотеки, select категории (run/ofp/sbu), кнопки вверх/вниз/удалить, грид NumField/TextField (подходы/повторы/дистанция/длительность/вес/темп). Не экспортируется.

### `ExerciseAdder({library, onAdd})` — L359
Внутренний компонент: кнопка «+ Добавить упражнение» (пустая строка) и select «+ из библиотеки…» (до 50 опций) — выбор подставляет дефолты упражнения (default_sets/reps/...). Не экспортируется.

### `NumField({label, value, onChange, step, float})` — L411
Внутренний числовой инпут: '' → null, parseInt/parseFloat. Не экспортируется.

### `TextField({label, value, onChange, placeholder})` — L430
Внутренний текстовый инпут ('' → null). Не экспортируется.

### `normalizeExercise(ex)` — L445
Приведение упражнения из API к форме редактора (null-дефолты, Number для weight_kg). Не экспортируется.

### `toIntOrNull(v)` — L460
parseInt с null для пустых/нечисловых. Не экспортируется.

### `toFloatOrNull(v)` — L466
parseFloat с null для пустых/нечисловых. Не экспортируется.

## `src/components/Coach/templateIcons.js` (52 строки)
Иконки шаблонов тренировок: поле template.emoji хранит ключ (zap/interval/...), для старых записей с реальным эмодзи — мапа EMOJI_TO_KEY.

### `TEMPLATE_ICON_OPTIONS` — L13
Экспортируемый const: 12 опций {key, Icon, label} (Темповая/Интервалы/Лёгкая/Длительная/Фартлек/СБУ/ОФП/Отдых/Бег/Холмы/Контроль/Гонка) на иконках из common/Icons. Потребитель: TemplateEditorModal.

### `getTemplateIcon(value)` — L47
Экспортируемая функция: Icon-компонент по ключу (KEY_TO_ICON L44) либо по легаси-эмодзи (EMOJI_TO_KEY L28), null если не найден. Потребители: TemplatesScreen, BulkAssignModal.

## `src/components/common/AppErrorBoundary.jsx` (115 строк)
Класс-границa ошибок приложения: карточка с заголовком/описанием и кнопками Повторить / Обновить / На главную; различает chunk-load-ошибки (устаревшая сборка).

### `AppErrorBoundary` (class) — L42
Default-export класс-компонент. `getDerivedStateFromError` → state.error; `componentDidCatch` → logger.error со стеками; `componentDidUpdate` — сброс ошибки при смене `props.resetKey`. В рендере: если `isChunkLoadError(error)` — текст «Страница обновилась на сервере» без кнопки Повторить; хендлеры handleRetry (сброс state), handleReload (location.reload), handleGoHome (location.assign('/')). Inline-стили (shellStyle/cardStyle/... L5-40). Потребители: main.jsx, App.jsx, AppTabsContent.jsx.

## `src/components/common/AthleteSelect.jsx` (104 строки)
Кастомный dropdown выбора атлета тренером (замена нативного select ради тёмной темы): «Мой календарь» + список атлетов, клавиатурная навигация.

### `AthleteSelect({value, ownLabel, athletes, onChange})` — L17
Default-export компонент. Строит items: [{slug:'', label: ownLabel}] + атлеты (label через `getDisplayName`). Стейт: open, activeIdx. Эффект L31: закрытие по mousedown вне, Escape (с возвратом фокуса кнопке), ArrowUp/Down — перемещение activeIdx, Enter — выбор. Рендер: кнопка со значением + ChevronDown, ul role="listbox" с option-кнопками (selected → CheckIcon, hover → activeIdx). onChange получает slug или null для «своего» режима. Потребители: CalendarScreen, StatsScreen.

## `src/components/common/BottomNavIcons.jsx` (115 строк)
Минималистичные stroke-SVG-иконки нижнего навбара (24×24, currentColor).

### Экспортируемые иконки
`NavIconHome` L18, `NavIconChat` L26 (пузырь с хвостиком), `NavIconCalendar` L32, `NavIconStats` L39, `NavIconUsers` L46, `NavIconMail` L56, `NavIconStream` L64 (молния), `NavIconAnalytics` L71, `NavIconLibrary` L80, `NavIconProfile` L88, `NavIconSettings` L96, `NavIconTrainers` L104 (свисток, fill-SVG viewBox 512). Все — компоненты без пропсов на общем `iconProps` (L6). Потребители: BottomNav, TopHeader, UserDrawer. **NavIconMail и NavIconProfile нигде не используются.**

## `src/components/common/BottomNav.jsx` (92 строки)
Мобильная нижняя навигация (v3 Variant C minimal): активная вкладка — оранжевая pill с лейблом, неактивные — иконки. Разные наборы вкладок для атлета и тренера.

### `BottomNav()` — L33
Default-export компонент. Сторы/хуки: `useAuthStore` (user.role, drawerOpen, setDrawerOpen), `useLocation`, `useNavigate`. Наборы: `userTabs` (L17: Главная/План/Чат/Прогресс/Меню) и `coachTabs` (L25: Команда/Поток(?view=stream)/Календарь/Чат/Меню). Функция `isActive` (L41): для drawer — drawerOpen; для '/' учитывает query `view` (stream vs table/grid); иначе startsWith pathname. `handleTabClick` — toggle drawer или navigate. Рендер через portal в #modal-root. Потребители: AppLayout, ChatScreen, UserProfileScreen и др.

## `src/components/common/ChatComposerInput.jsx` (156 строк)
Rich-поле ввода чата (contenteditable) с инлайновыми Apple-эмодзи. Через ref ведёт себя как `<input>` (get/set value), поэтому submit/clear-логика отправки не меняется.

### `serialize(el)` — L15
Рекурсивная сериализация contenteditable → строка: TEXT_NODE как есть, `<img data-emoji>` → native-эмодзи, `<br>`/блочные обёртки → '\n'. Не экспортируется.

### `makeEmojiImg(native, unified)` — L34
Создаёт `<img class="emoji-img">` с src `appleEmojiImageURL(unified)`, alt/data-emoji = native. Не экспортируется.

### `insertNodeAtCaret(el, node)` — L44
Вставляет узел в позицию каретки через Selection/Range (или в конец, если выделение вне поля), переносит курсор за узел. Не экспортируется.

### `ChatComposerInput({onChange, onValueChange, placeholder, disabled, className, maxLength}, ref)` — L68
Default-export forwardRef-компонент. useImperativeHandle (L87): геттер/сеттер `value` (serialize / textContent), `focus()`, `insertEmoji({native, unified})` (лимит maxLength 4000), геттер `el`. Хендлеры: `handleKeyDown` (L110) — Enter → requestSubmit ближайшей формы (Shift+Enter — перенос; guard IME-композиции keyCode 229), `handlePaste` (L121) — только plain text через Range. Класс `is-empty` синхронизируется для CSS-плейсхолдера. Потребитель: ChatScreen.

## `src/components/common/ChatEmojiPicker.jsx` (72 строки)
Кнопка-смайл + панель emoji-mart (Apple-набор как в Telegram). Мобайл: панель снизу во всю ширину; десктоп: поповер. Пикер грузится лениво отдельным чанком.

### `isMobileWidth()` — L13
window.innerWidth <= 640. Не экспортируется.

### `ChatEmojiPicker({onPick})` — L17
Default-export компонент. `EmojiMartLazy = lazy(import('./EmojiMartLazy'))` (L11). Стейт: open, theme (из data-theme при открытии), mobile. toggle (L25) фиксирует тему/мобильность на момент открытия. Эффект L36: Escape + pointerdown вне (листенер вешается через setTimeout 0, чтобы не закрыться от того же клика). Рендер: кнопка SmileIcon + панель с `<Suspense>`-обёрткой EmojiMartLazy (dynamicWidth на мобиле). Потребитель: ChatScreen.

## `src/components/common/ChatNotificationButton.jsx` (43 строки)
Кнопка чата в TopHeader с бейджем непрочитанных (SSE real-time). Для админов с непрочитанными открывает админ-режим чата.

### `ChatNotificationButton()` — L15
Default-export компонент. Сторы/хуки: `useAuthStore` (user.role), `useChatUnread` (total), `useNavigate`. Клик: navigate('/chat') со state `{openAdminMode|openAdminTab: true}` если есть непрочитанные. Бейдж «99+» при >99. Потребитель: TopHeader.

## `src/components/common/emojiAssets.js` (9 строк)
Единый источник пути к локальным Apple-эмодзи (public/emoji/apple) для пикера и рендера сообщений — без подтягивания тяжёлого emoji-mart.

### `APPLE_EMOJI_BASE` — L7
Экспортируемый const '/emoji/apple'.

### `appleEmojiImageURL(unified)` — L9
Экспортируемая функция: URL png-эмодзи по unified-коду. Потребители: EmojiText, ChatComposerInput.

## `src/components/common/EmojiMartLazy.jsx` (35 строк)
Ленивый враппер emoji-mart Picker с локальным Apple-спрайтшитом (apple.json + /emoji/apple-sheet-64.png, кэш-бастинг ?v=1501), локаль ru, офлайн-safe без CDN.

### `EmojiMartLazy({theme, onPick, dynamicWidth})` — L17
Default-export компонент. Рендерит `<Picker>` (@emoji-mart/react) в spritesheet-режиме: data=apple.json, getSpritesheetURL→SHEET_URL (L15), без preview/skin-tone/search, nav снизу, maxFrequentRows=2; onEmojiSelect → onPick({native, unified}). Потребитель: ChatEmojiPicker (через lazy).

## `src/components/common/EmojiText.jsx` (95 строк)
Рендерит текст, заменяя unicode-эмодзи на локальные Apple-картинки (единый вид на всех ОС). Карта native→unified грузится лениво из @emoji-mart/data; без эмодзи — нулевой оверхед (возврат исходной строки). Границы кластеров — Intl.Segmenter (ZWJ/флаги/keycap).

### `ensureMap()` — L18
Лениво импортирует @emoji-mart/data, строит Map native→unified по всем skins (модульный кэш nativeToUnified/mapPromise); при ошибке — пустая Map. Не экспортируется.

### `getSegmenter()` — L43
Ленивый синглтон Intl.Segmenter('ru', grapheme), null если недоступен. Не экспортируется.

### `EmojiText({text})` — L52
Default-export компонент. Быстрый тест `/\p{Extended_Pictographic}/u` (L12); если эмодзи есть — эффект подгружает карту (стейт ready), затем сегментирует текст на графемы и собирает массив узлов: строки + `<img class="emoji-img">` для известных эмодзи. До готовности карты возвращает исходный текст. Потребитель: ChatScreen.
