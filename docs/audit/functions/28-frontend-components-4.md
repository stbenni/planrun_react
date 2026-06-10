# Frontend components 4/6 (Dashboard ч.2 + v3, Integrations, Login, Onboarding ч.1) — справочник

## `src/components/Dashboard/TrainingLoadWidget.jsx` (602 строки)
Виджет тренировочной нагрузки (TRIMP/ATL/CTL/TSB): SVG-график за 30 дней с зоной оптимальной нагрузки (0.8–1.3×CTL), бейджи TSB/ACWR, рекомендации. Используется ТОЛЬКО старым `Dashboard.jsx` (не импортируется в v3 — там его роль играет `FormSectionV3`).

### `TERM_TIPS` — L15
Const: тексты тултипов для терминов TSB/ATL/CTL/ACWR (заголовок + объяснение).

### `TSB_STATES` — L39
Const: пороги TSB по стандарту TrainingPeaks PMC (+25 восстановлен … −30 перегрузка) с лейблами и CSS-модификаторами.

### `ACWR_LABELS` — L48
Const: маппинг acwr_status → {label, cssmod} (insufficient/detrained/optimal/caution/risk).

### `getTsbState(tsb)` — L56
Находит первое состояние из TSB_STATES, чей min ≤ tsb.

### `MONTH_SHORT` — L62
Const: короткие русские названия месяцев.

### `fmtDateShort(dateStr)` — L64
Форматирует дату как «5 июн».

### `getRecommendations(atl, ctl, tsb)` — L71
Строит 3 текстовые рекомендации (с иконкой и тоном) по диапазону TSB: целевой TRIMP-коридор 0.8–1.3×CTL, предупреждение при ATL > 1.4×CTL и при TSB < −30.

### `VB_W`/`VB_H`/`MARGIN_FULL`/`MARGIN_COMPACT`/`TOOLTIP_EDGE_PADDING`/`TOOLTIP_OFFSET` — L107–112
Const: размеры viewBox SVG (800×250), отступы для full/compact режимов, паддинги тултипа.

### `TrainingLoadWidget({api, viewContext, compact})` — L116
Компонент (default export). Пропсы: `api` (вызывает `api.getTrainingLoad(viewContext)`), `viewContext` (контекст просмотра тренером), `compact` (только бейдж + упрощённый график, без рекомендаций). Рендерит header с бейджами TSB/ACWR и метриками ATL/CTL, SVG-график (grid, опт. зона, нулевая линия, 3 полилинии, оси, hover-overlay), HTML-тултип с авто-позиционированием через ResizeObserver, легенду и рекомендации. Сторы/хуки: `useWorkoutRefreshStore` (перезагрузка по version), `InfoTooltip`, `LogoLoading`. Значимая внутренняя логика: `load` (L126, fetch + state), useMemo `chart` (L150, расчёт линий/осей/зоны), `handleMouseMove`/`handleTouchMove` (L258/273, hover-индекс из координат), `tooltipData` (L302), `updateTooltipLayout` (L319, flip left/right у краёв) + два useLayoutEffect (L354, L358 с ResizeObserver).

## `src/components/Dashboard/TrendComparisonWidget.jsx` (162 строки)
Сравнение «последние 30 дней vs предыдущие 30»: дельты дистанции, числа тренировок, среднего темпа. Используется ТОЛЬКО старым `Dashboard.jsx`; в v3 аналог — `TrendsSmallV3`/`StatsSectionV3`.

### `WINDOW_DAYS` — L11
Const: размер окна сравнения (30 дней).

### `dateKey(d)` — L13
Date → строка YYYY-MM-DD.

### `aggregate(workoutsByDate, fromDate, toDate)` — L20
Проходит по дням диапазона, суммирует км/секунды/count из summary-объекта; возвращает {distKm, workouts, paceSecPerKm}.

### `formatDelta(value, opts)` — L47
Форматирует числовую дельту со знаком и единицей; tone up/down/neutral (с инверсией через opts.invert).

### `formatPaceDelta(curr, prev)` — L59
Дельта темпа в сек/км → текст «−м:сс»/«+Nс»; меньше = быстрее → tone 'up'.

### `TrendComparisonWidget({workoutsByDate})` — L74
Компонент (default export). Пропс: `workoutsByDate` (summary по дням). Считает current/previous через `aggregate` (useMemo), рендерит 3 карточки метрик (дистанция/тренировки/темп) с дельтой, иконкой тренда и «было …». API не вызывает.

## `src/components/Dashboard/useDashboardData.js` (569 строк)
Главный data-хук дашборда (используется и DashboardV3): параллельная загрузка плана/результатов/тренировок, поиск «сегодня»/«следующая», прогресс недели, метрики. Плюс набор чистых функций-агрегаторов.

### `DAY_KEYS` — L11
Const: ['mon'..'sun'].

### `isAiPlanMode(trainingMode)` — L13
true если режим === 'ai'.

### `getSummaryObject(workoutsSummaryRes)` — L17
Нормализует ответ getAllWorkoutsSummary: `.workouts` либо сам объект.

### `buildResultsData(allResults)` — L27
Группирует results по training_date → {date: [results]}.

### `buildWorkoutsList(workoutsListRes)` — L39
Достаёт массив workouts из вариантов формы ответа.

### `buildWorkoutsListByDate(workoutsList)` — L43
Группирует тренировки по дате (date или start_time).

### `hasAnyPlannedWorkout(weeksData)` — L56
true если в каком-либо дне плана есть хоть один item.

### `buildProgressDataMap(plan, summaryObj, allResults, workoutsListByDate, executedByDate)` — L68
Для каждой даты с активностью вызывает `getDayCompletionStatus`; возвращает map {date: true} для completed-дней.

### `findDashboardWorkouts(plan, user)` — L89
Ищет в weeks_data тренировку на сегодня (в таймзоне юзера, `getTodayInTimezone`) и ближайшую будущую; возвращает {todayWorkout, nextWorkout, currentWeek} с прикреплёнными planDays.

### `pickTodayActual(dateStr, workoutsListByDate, resultsData, summaryObj)` — L147
Фактические метрики за дату для выполненной карточки «Сегодня»: приоритет workout → result → summary; возвращает {distance_km, pace, avg_heart_rate, duration_minutes, activity_type}.

### `decorateTodayCompletion(todayWorkout, plan, user, summaryObj, allResults, workoutsListByDate, executedByDate)` — L185
Помечает сегодняшнюю тренировку completed (по `getDayCompletionStatus`) и добавляет `actual` из `pickTodayActual`, чтобы карточка показывала результат вместо CTA.

### `hasWorkoutForCategory(dateStr, category, workoutsList, allResults, summaryObj, executedByDate)` — L207
Проверяет наличие выполненной активности нужной категории за дату: workouts → results → summary → executed_exercises (ОФП/СБУ через mark_exercises_completed).

### `calculateWeekProgress(currentWeek, workoutsList, allResults, summaryObj, executedByDate)` — L251
Собирает плановые дни недели с категориями и считает {completed, total} через `hasWorkoutForCategory`.

### `getPlanCategories(plan)` — L278
Set категорий активностей всего плана (через planTypeToCategory).

### `buildMetrics(summaryObj, allResults, plan, workoutsList)` — L302
Метрики текущей календарной недели (пн–вс): км/кол-во/часы; если план есть — фильтрует workouts по категориям плана; возвращает также hasWalking.

### `useDashboardData({api, user, isTabActive, registrationMessage, isNewRegistration, clearPlanMessage})` — L343
Хук (named export). Сторы: `usePlanStore` (planStatus, isGenerating, setPlan, checkPlanStatus, regeneratePlan), `useWorkoutRefreshStore` (version), `usePreloadStore` (triggerPreload на native). API: getPlan, getAllResults, getAllWorkoutsSummary, getAllWorkoutsList(null,500), getExecutedDates(26) — параллельным Promise.all с переиспользованием данных из store. Эффекты: загрузка при маунте/смене timezone, silent-перезагрузка при visibilitychange, debounce 250мс на workoutRefreshVersion, поллинг каждые 10с при planGenerating. `handleRegeneratePlan` (L514) — запуск регенерации через store с обработкой ошибок. Возвращает ~20 полей: loading, plan, planExists, todayWorkout, nextWorkout, weekProgress, metrics, progressDataMap, workoutsByDate, noPlanChecked, planGenerating, handleRegeneratePlan и др.

## `src/components/Dashboard/useDashboardPullToRefresh.js` (109 строк)
Pull-to-refresh для дашборда через touch-события на контейнере + хаптика на native.

### `PULL_THRESHOLD`/`MAX_PULL` — L5–6
Const: порог срабатывания (50px) и максимум растяжения (100px).

### `fireHapticImpact()` — L9
Лёгкий тактильный отклик (Haptics.impact Medium) при пересечении порога; только native, тихий fail.

### `fireHapticSuccess()` — L19
Haptics.notification Success после успешного refresh; только native.

### `useDashboardPullToRefresh(dashboardRef, loadDashboardData)` — L29
Хук (named export). Вешает touchstart/touchmove/touchend на dashboardRef: при scrollTop===0 тянет вниз, при превышении порога вызывает `loadDashboardData()` + `useWorkoutRefreshStore.triggerRefresh()`. Возвращает {refreshing, pullDistance}.

## `src/components/Dashboard/v3/DashboardV3.jsx` (395 строк)
Главный экран бегуна (заменил старый Dashboard.jsx для не-тренеров; импортируется DashboardScreen как `Dashboard`). Композиция секций today/week/form/stats/goal/pr/trends/race/pace с управлением видимостью через DashCustomizerV3.

### `isAiPlanMode(trainingMode)` — L50
true если 'ai' (локальный дубликат из useDashboardData).

### `DashboardV3({api, user, isTabActive, onNavigate, registrationMessage, isNewRegistration})` — L54
Компонент (default export). Сторы/хуки: `useAuthStore` (setPlanGenerationMessage, updateUser), `usePlanStore` (generationLabel), `useDashboardData`, `useDashboardPullToRefresh`, `useNavigate`, виджеты из localStorage через `getEnabledWidgets` + window-событие 'dashboard-v3-widgets-changed'. Состояния: онбординг не пройден → welcome-карточка; loading → SkeletonScreen; генерация плана → PlanGeneratingState; AI без плана → empty-state «Создать план». Основной рендер: DashHeaderV3, две колонки (main: TodayHeroV3/rest/empty + NextWorkoutSectionV3 + WeekSectionV3 + FormSectionV3 + StatsSectionV3; side: GoalSectionV3 + GoalCountdownWidget + PRSectionV3 + TrendsSmallV3 + RacePredictionV3 + PaceZonesSectionV3), кнопка «Настроить дэшборд» → DashCustomizerV3, ModeSwitchPopup, DashFabAi. Значимые хендлеры: `handleModeSelect` (L70, ~60 строк: coach→ai/self через getMyCoaches/removeCoach + confirm; →coach через update_profile или каталог /trainers; ai/self → /onboarding с state.mode), `weekSummary` useMemo (L192, «N ключ. · M км» текущей недели), `handleWorkoutPress` (навигация в календарь на дату).

## `src/components/Dashboard/v3/DashCustomizerV3.jsx` (166 строк)
Модалка управления виджетами дашборда: 3 пресета + индивидуальные тумблеры; состояние в localStorage 'dashboard-v3-widgets'.

### `WIDGETS` — L12
Const: 10 виджетов {id, name, emoji, desc, fixed?, preset[]}; 'today' — fixed.

### `PRESETS` — L25
Const: simple/standard/pro с названиями и описаниями.

### `STORAGE_KEY` — L31
Const: 'dashboard-v3-widgets'.

### `getEnabledWidgets()` — L33
Named export. Читает localStorage; fallback — пресет standard; всегда добавляет 'today'. Возвращает Set id.

### `getPresetEnabled(preset)` — L46
Set id виджетов, входящих в пресет (+ 'today').

### `saveEnabled(set)` — L52
Сохраняет в localStorage и диспатчит CustomEvent 'dashboard-v3-widgets-changed'.

### `DashCustomizerV3({isOpen, onClose})` — L60
Компонент (default export). Рендерит через createPortal в #modal-root: пресеты, список тумблеров (fixed = заблокирован), футер «Готово». Esc закрывает, body scroll блокируется. Хендлеры `toggle`/`applyPreset` сохраняют через `saveEnabled`.

## `src/components/Dashboard/v3/DashFabAi.jsx` (20 строк)
Mobile-only FAB для AI-чата в правом нижнем углу.

### `DashFabAi({onOpen, mode})` — L9
Компонент (default export): одна кнопка с BotIcon; aria-label зависит от mode (ai/тренер).

## `src/components/Dashboard/v3/DashHeaderV3.jsx` (80 строк)
Заголовок дашборда v3: аватар + дата + приветствие слева, кликабельный mode-badge справа.

### `MONTHS_GEN`/`WEEKDAYS`/`WEEKDAYS_FULL` — L10–12
Const: русские месяцы (род. падеж) и дни недели (кратко/полностью).

### `formatEyebrowMobile(d)` — L14
«ВТ · 12 МАЯ» (uppercase).

### `formatEyebrowDesktop(d)` — L18
«Вторник · 12 мая».

### `MODE_LABEL` — L23
Const: {ai: 'AI-тренер', coach: 'Тренер', self: 'Сам'}.

### `DashHeaderV3({user, mode, weekSummary, onModeClick})` — L25
Компонент (default export). Рендерит CoachAvatar (из Coach/CoachPrimitives), обе версии даты (CSS прячет ненужную), «Привет, {имя}», weekSummary; справа кнопка режима с глифом (AI/✎/МК), online-точкой и caret. API/сторов нет.

## `src/components/Dashboard/v3/DashStickyTabsV3.jsx` (90 строк)
Sticky-навигация по секциям дашборда (scrollspy + smooth-scroll). ВНИМАНИЕ: импортируется только старым Dashboard.jsx; в DashboardV3 упомянут лишь в комментарии — фактически не подключён.

### `DEFAULT_TABS` — L13
Const: 6 табов (today/week/goal/form/pr/more).

### `DashStickyTabsV3({tabs})` — L22
Компонент (default export). IntersectionObserver по [data-section] подсвечивает активный таб (rootMargin -90px/-50%); активный chip центрируется горизонтальным scrollTo (не scrollIntoView — iOS-баг); `handleClick` (L62) — smooth-scroll к секции с offset 96px и блокировкой scrollspy на 700мс.

## `src/components/Dashboard/v3/FormSectionV3.jsx` (377 строк)
Секция «Форма и нагрузка» v3: hero-TSB + график ATL/CTL/TSB + mini-stats ACWR/TRIMP + рекомендация. v3-замена TrainingLoadWidget.

### `TERM_HINTS` — L13
Const: тексты тултипов ACWR/TRIMP/TSB.

### `tsbStatus(tsb)` — L28
TSB → {label: Свежий/Норма/Усталость/Перегруз, color} (пороги 5/−10/−20).

### `acwrLabel(status)` — L35
acwr_status → {text, color} для mini-stat.

### `FormSectionV3({api, viewContext})` — L43
Компонент (default export). Фетчит `api.getTrainingLoad(viewContext, days)` (селектор 28/56/90 дн). Рендерит hero-число TSB с лейблом, LineChart с тремя сериями, легенду (LegendItem×3), mini-stats (ACWR, TRIMP сегодня, TRIMP 7дн), условные рекомендации при tsb≥5 / tsb<−15. Пустое состояние при !available.

### `MiniStat({label, value, sub, color, info})` — L151
Под-компонент: мини-метрика с опциональным InfoBadge.

### `LegendItem({color, label, value, info})` — L166
Под-компонент: элемент легенды (цветная линия + лейбл + значение).

### `InfoBadge({term})` — L183
Под-компонент: иконка «i» с тултипом через createPortal в body (hover/focus/click); `recalcPos` (L189) центрирует над иконкой с clamp по viewport, пересчёт на resize/scroll.

### `LineChart({series, dates, w, h})` — L246
Под-компонент: SVG-мультилиния (viewBox 300×120, preserveAspectRatio none) с нулевой пунктирной линией, точками на конце, hover/touch-курсором (вертикальная линия + точки) и HTML-тултипом с датой и значениями серий.

### `formatTooltipDate(iso)` — L367
ISO → «5 июн, чт» (toLocaleDateString ru-RU).

### `formatChartValue(v)` — L373
Округляет и добавляет «+» для неотрицательных.

## `src/components/Dashboard/v3/GoalSectionV3.jsx` (195 строк)
Секция «Главная цель»: countdown до старта + прогресс недель плана + цель vs VDOT-прогноз.

### `USER_DIST_TO_KEY` — L11
Const: маппинг user.race_distance → ключ predictions ('half_marathon'→'half' и т.п.).

### `DISTANCE_LABELS` — L16
Const: дистанция → русский лейбл.

### `MONTHS_GEN` — L21
Const: месяцы в род. падеже.

### `daysToRace(iso)` — L23
Дней до даты (ceil, min 0) или null.

### `formatRaceDate(iso)` — L30
«14 сентября».

### `GoalSectionV3({user, plan, api})` — L37
Компонент (default export). Берёт цель из user (race_date/target_marathon_date, race_distance, race_target_time); прогресс недель через `useWeeksProgress(plan)`; фетчит `api.getRacePrediction()` и достаёт прогноз по целевой дистанции + дельту против цели (`computeDelta`). Рендерит тёмный countdown-box с днями и прогресс-баром «Неделя N/M · Фаза», блок ЦЕЛЬ → ПРОГНОЗ с цветовой подсветкой (быстрее/медленнее). Пустое состояние без даты/дистанции.

### `parseHhMmSs(t)` — L137
«Ч:ММ:СС»/«ММ:СС» → секунды или null.

### `formatDeltaSec(sec)` — L145
Секунды → «N сек»/«M мин»/«M:SS».

### `computeDelta(targetTime, predictedTime)` — L154
Сравнение цели и прогноза: {faster|slower, text} либо «точно цель».

### `derivePhase(weeksDone, weeksTotal)` — L168
Фаза цикла по % прогресса: <25% базовая, <50% развивающая, <80% пиковая, <100% подводка, иначе старт.

### `useWeeksProgress(plan)` — L178
Функция (не настоящий хук — без React-хуков внутри): собирает все недели из plan.phases либо weeks_data, считает {weeksDone (недели, закончившиеся до сегодня), weeksTotal}.

## `src/components/Dashboard/v3/ModeSwitchPopup.jsx` (85 строк)
Bottom-sheet выбора режима тренировок (AI/тренер/сам) с текущим режимом и предупреждением.

### `MODE_INFO` — L6
Const: метаданные режимов {label, desc, kind, glyph}.

### `ORDER` — L11
Const: порядок режимов ['ai','coach','self'].

### `ModeBadge({kind, glyph, size})` — L13
Под-компонент: круглый бейдж режима с глифом.

### `ModeSwitchPopup({open, currentMode, busy, onClose, onSelect})` — L21
Компонент (default export). createPortal в #modal-root/body: scrim (клик закрывает, кроме busy), текущий режим с pill «Активен», кнопки переключения на остальные два (`onSelect(m)`, disabled при busy), предупреждение о смене источника плана. Esc закрывает.

## `src/components/Dashboard/v3/NextWorkoutSectionV3.jsx` (156 строк)
Карточка следующей запланированной тренировки: stripe цвета типа, заголовок, мета (темп/ЧСС/время), км, CTA «Открыть детали».

### `TYPE_LABELS`/`TYPE_PROPER`/`TYPE_INTERVAL_SUFFIX`/`TYPE_DEFAULT_HR_ZONE` — L9–38
Const: лейблы типов, формы в мужском роде («Лёгкий»), суффиксы для интервалов («в темпе»), дефолтные зоны ЧСС по типу.

### `DOW_SHORT`/`MONTHS_GEN` — L40–41
Const: дни недели/месяцы.

### `formatDateLine(iso)` — L43
«СР · 11 июня».

### `NextWorkoutSectionV3({workout, onOpen})` — L50
Компонент (default export). Достаёт km/pace/dur/hrZone из полей workout либо парсингом description (`parseDescription`); строит заголовок: «Отдых»/«ОФП»/«СБУ», «4×1 км в темпе» для интервальных, «Лёгкий 8 км», fallback TYPE_LABELS. Цвет stripe из WORKOUT_TYPE_COLOR (Coach/CoachPrimitives). Клик по карточке и CTA → onOpen.

### `parseDescription(text)` — L119
Парсит multiline description бэка regex'ами (lookahead вместо \b — кириллица): км, темп «5:45 мин/км», длительность «Ч:ММ:СС», интервалы «4×1 км», зона ЧСС. Возвращает {km, pace, dur, intervals, hrZone}.

## `src/components/Dashboard/v3/PaceZonesSectionV3.jsx` (87 строк)
Таблица тренировочных зон (восстановительный/E/M/T/I) с темпами из VDOT.

### `DEFAULT_ZONES` — L10
Const: 5 зон-заглушек с «—».

### `buildZonesFromPaces(paces)` — L19
Маппит backend training_paces (easy/marathon/threshold/interval) на 5 строк зон; восстановительный — производный.

### `deriveRecoveryPace(easyStr)` — L34
Берёт медленную границу easy-темпа и строит диапазон +30…+60 сек/км.

### `PaceZonesSectionV3({zones, api})` — L50
Компонент (default export). Если zones не переданы — фетчит `api.getRacePrediction()` и берёт training_paces. Рендерит список строк: stripe цвета типа (WORKOUT_TYPE_COLOR), имя зоны, назначение, темп.

## `src/components/Dashboard/v3/PRSectionV3.jsx` (121 строка)
4 карточки личных рекордов (5K/10K/21.1K/42.2K) с временем, датой, VDOT и бейджем «НОВЫЙ».

### `SLOTS` — L10
Const: 4 слота с диапазонами км (совпадают с backend StatsService::getBestRacesProgression).

### `MONTHS_GEN` — L17
Const: короткие месяцы.

### `formatDateShort(iso)` — L19
«5 мая» или null.

### `isFreshDate(iso)` — L26
true если запись моложе 14 дней (для бейджа «★ НОВЫЙ»).

### `PRSectionV3({api, compact})` — L33
Компонент (default export). Фетчит `api.getPersonalRecords()`, мапит на слоты через `mapRecordsToSlots`; рендерит грид карточек (пустые — с «—», свежие — с бейджем, VDOT-pill).

### `mapRecordsToSlots(records)` — L79
Для каждого слота фильтрует записи по диапазону км и выбирает лучшее (мин. время через `recordTimeSec`); возвращает {label, time, date, vdot, fresh}.

### `recordTimeSec(r)` — L102
time_sec либо парсинг result_time/time «Ч:ММ:СС» → секунды; Infinity если нет.

### `formatTime(r)` — L112
Готовая строка result_time/time либо формат из секунд (Ч:ММ:СС / М:СС).

## `src/components/Dashboard/v3/RacePredictionV3.jsx` (76 строк)
VDOT-прогнозы для 4 дистанций; целевая дистанция юзера подсвечена pill «ЦЕЛЬ».

### `DISTANCES` — L10
Const: 4 дистанции {key, label}.

### `USER_DIST_TO_KEY` — L17
Const: маппинг race_distance → ключ предикшена (дубликат из GoalSectionV3).

### `RacePredictionV3({api, user})` — L22
Компонент (default export). Фетчит `api.getRacePrediction()`; при !available — заглушка. Рендерит eyebrow с VDOT, список строк: дистанция (+ «ЦЕЛЬ» если совпала с user.race_distance), время pred.formatted и pace_formatted.

## `src/components/Dashboard/v3/StatsSectionV3.jsx` (108 строк)
Статистика бега за период (Мес/Квартал/Год): 4 тайла — дистанция/тренировки/время/средний темп. Источник — workoutsByDate (без API).

### `PERIODS` — L11
Const: month=30/quarter=90/year=365 дней.

### `StatsSectionV3({workoutsByDate})` — L17
Компонент (default export). Селектор периода + useMemo `computeStats`; рендерит 4 Tile.

### `Tile({label, value, unit})` — L50
Под-компонент: тайл метрики.

### `computeStats(workoutsByDate, period)` — L62
Агрегация summary за период (cutoff от Date.now): суммирует distance/count/duration, считает средний темп (мин/км → «М:СС») и время «Ч:ММ».

## `src/components/Dashboard/v3/TodayHeroV3.jsx` (337 строк)
Hero-карточка «Сегодня»: тип-риббон, крупный заголовок, метрики (план или факт), interval-bar сегментов, AI-цитата (брифинг/разбор), CTA.

### `TYPE_LABELS` — L19
Const: лейблы типов тренировок.

### `TYPE_ACCENT` — L26
Const: цветное прилагательное для заголовка («лёгкий бег», «в темпе»).

### `BRIEFING_MAX_AGE_HOURS` — L38
Const: 36 — максимальный возраст проактивного сообщения.

### `TodayHeroV3({workout, plan, large, api, onOpenChat, onStart, onReschedule, onMarkDone})` — L40
Компонент (default export). Фетчит `api.getLatestProactiveMessage(type, 36)` — 'daily_briefing' до выполнения, 'post_workout_analysis' после. Использует `buildRunSegments`/`suggestPaceByType`/`estimateTimeMin` из Calendar/v3/calV3 и WORKOUT_TYPE_COLOR. Заголовок: «X км / акцент» либо «4×1 км / в темпе» (useMemo L84); для выполненной показывает фактические метрики (км/темп/пульс) из workout.actual; interval-bar из exercises (≥2) либо buildRunSegments по описанию (useMemo L117); AI-цитата — кнопка → onOpenChat; CTA «Начать/Перенести/Выполнено» скрыты при completed.

### `Metric({n, l, accent})` — L256
Под-компонент: число + подпись метрики.

### `parseDescription(text)` — L273
Парсит multiline description: км, темп, длительность, интервалы, строка-заголовок без цифр (lookahead вместо \b). Возвращает {km, pace, dur, title, intervals}. (Почти дубликат версии из NextWorkoutSectionV3 + title.)

### `formatKm(km)` — L324
Число → «8,0» (v2-стиль с запятой) или «—».

### `formatTime(iso)` — L332
ISO/SQL-datetime → «ЧЧ:ММ» (ru-RU).

## `src/components/Dashboard/v3/TrendsSmallV3.jsx` (110 строк)
Компактный тренд: объём за 30 дней vs предыдущие 30 + sparkline по 4 неделям.

### `TrendsSmallV3({workoutsByDate})` — L10
Компонент (default export). useMemo `computeTrend`; Sparkline из Coach/CoachPrimitives (цвет по знаку дельты), ширина через ResizeObserver. Рендерит число км, дельту в %, спарклайн с подписями недель.

### `computeTrend(workoutsByDate)` — L59
Суммирует км: 0–30 дней (curr), 30–60 (prev), недельные корзины за 28 дней для спарклайна; лейблы «27 апр – 3 мая»; deltaPct = (curr−prev)/prev.

## `src/components/Dashboard/v3/WeekSectionV3.jsx` (237 строк)
Лента 7 дней текущей недели: dow+дата, stripe типа, название, km·темп, чек выполнено / pill «СЕГОДНЯ», шапка с суммой км и прогрессом N/M.

### `DOW`/`TYPE_LABELS`/`TYPE_PROPER`/`TYPE_INTERVAL_SUFFIX` — L12–34
Const: дни недели, лейблы/мужские формы типов, суффиксы интервалов (дубликаты NextWorkoutSectionV3).

### `isoDayFromDate(d)` — L36
Date → YYYY-MM-DD.

### `fmtRangeShort(start, end)` — L37
«8–14 июн.».

### `WeekSectionV3({plan, workoutsByDate, progressDataMap, compact})` — L41
Компонент (default export). useMemo `buildWeek(plan)`; считает totalKm (сумма km дней либо парсинг total_volume), plannedDays, doneCnt через `isDone`. Рендерит шапку и 7 строк дней с подсветкой сегодня/выполнено. API не вызывает.

### `DAY_KEYS` — L114
Const: ['mon'..'sun'].

### `buildWeek(plan)` — L116
Находит неделю с сегодняшней датой среди plan.phases[].weeks_data либо plan.weeks_data (fallback — первая); для каждого из 7 дней берёт primary item (не rest/free), извлекает label/km (`buildDayLabel`), pace, isKey. Возвращает {start, end, days[7], weekNumber, totalVolume}.

### `buildDayLabel(type, text)` — L175
Читаемый label: «Отдых»/«ОФП»/«СБУ»/«Ходьба X км»; интервалы «4×1 км [в темпе]»; «Лёгкий 6 км»; fallback TYPE_LABELS. Возвращает {label, km}.

### `extractKm(text)` — L202
Первая «X км» из строк описания (regex с lookahead) → число или 0.

### `extractPace(text)` — L216
Темп «5:45/км» либо «Темп: 5:45» → строка или null.

### `extractIntervals(text)` — L224
«4×1 км» → {reps, dist, unit, text} или null.

### `isDone(day, workoutsByDate, progressDataMap)` — L233
true если день в progressDataMap либо есть workouts за дату.

## `src/components/Integrations/useHealthConnect.js` (84 строки)
Хук состояния/действий Health Connect для экрана интеграций (SettingsScreen). На web/не-Android available=false.

### `useHealthConnect(api, notify)` — L16
Хук (default export). Обёртки над services/healthConnectSync: `refresh` (L25, isHealthConnectAvailable + права + локальный disable), `connect` (L42, connectAndSyncHealthConnect + notify с числом импортированных), `sync` (L57, syncHealthConnect), `disconnect` (L70, confirm + disconnectHealthConnect). Возвращает {available, status, connected (=granted && !disabled), busy, connect, sync, disconnect, refresh}.

## `src/components/LoginForm.jsx` (217 строк)
Переиспользуемая форма входа (страница/модалка) со встроенным сбросом пароля и сохранением recovery-кредов по PIN на native.

### `LoginForm({onSuccess, onLogin})` — L18
Компонент (default export). Сторы/хуки: `useAuthStore.login` (либо проп onLogin), `useRetryCooldown` (кулдаун после rate-limit), `usePasswordResetRequest` (forgot-флоу), `PinAuthService.isPinEnabled`, `CredentialBackupService` (saveCredentialsSecure / saveCredentials по PIN), `isNativeCapacitor`, `getAuthErrorMessage`/`getAuthRetryAfter`. Три вью: 'login' (логин/пароль + «Забыли пароль?»), 'forgot' (запрос ссылки на email с кулдауном), PIN-экран (PinInput) для сохранения recovery после успешного входа. Значимые хендлеры: `handleSubmit` (L47, логин с useJwt на native, сохранение кредов, переход на PIN-шаг при включённом PIN, кулдаун по retryAfter), `handlePinForRecoveryComplete` (L84, saveCredentials по 4-значному PIN и onSuccess).

## `src/components/LoginModal.jsx` (32 строки)
Модальное окно входа для лендинга (LandingScreen, UserProfileScreen) — живое.

### `LoginModal({isOpen, onClose})` — L9
Компонент (default export). Оборачивает LoginForm в Modal (small, hideHeader, centerBody); onSuccess только закрывает модалку — навигацию делает LandingScreen по isAuthenticated (анти-«дёрганье»).

## `src/components/Onboarding/onboardingForm.js` (206 строк)
Стейт формы онбординга (специализации), нормализация payload и все константы-справочники. Поведение 1:1 со старым RegisterScreen.

### `getNextMonday()` — L9
Exported. Следующий понедельник YYYY-MM-DD (дефолт training_start_date).

### `createInitialOnboardingState()` — L19
Exported. Полное начальное состояние формы: режим, цель (race/weight/health-поля), профиль (имя, пол, рост/вес, опыт, объём, дни, ОФП, время), расширенный профиль (история забегов, easy-pace, последний результат).

### `seedOnboardingFromUser(user, mode)` — L72
Exported. Сид при смене режима: пустой стейт + предвыбранный режим + предзаполнение непустыми полями юзера (имя, цель, гонка, антропометрия, опыт, объём, preferred_days→sessions_per_week).

### `buildSpecializationPayload(formData)` — L100
Exported. Payload для completeSpecialization: булевы → 0/1, sessions_per_week из числа выбранных дней.

### `isPlanGenerationMode(trainingMode)` — L112
Exported. true для 'ai' (режимы с генерацией AI-плана).

### `DAY_LABELS` — L117
Exported const: ключ дня → «Пн»…

### `GOALS` — L122
Exported const: 4 цели (health/race/weight_loss/time_improvement) с iconKey/title/desc.

### `HEALTH_PROGRAMS` — L130
Exported const: 4 программы цели «Здоровье» (start_running, couch_to_5k, regular_running, custom).

### `HEALTH_PLAN_WEEKS` — L138
Exported const: сроки custom-программы (4/8/12/16 недель).

### `EXPERIENCE_LEVELS` — L146
Exported const: 5 уровней опыта novice…expert с периодами.

### `WEEKLY_VOLUME_RANGES` — L160
Exported const: чипы-диапазоны недельного объёма; km = середина диапазона для бэка; exact у верхних.

### `RACE_DISTANCES` — L170
Exported const: 5k/10k/half/marathon.

### `LAST_RACE_DISTANCES` — L178
Exported const: дистанции последнего результата (+ '', other).

### `OFP_PREFERENCES` — L188
Exported const: где делать ОФП (зал/дом/оба/группы/онлайн).

### `TRAINING_TIMES` — L198
Exported const: утро/день/вечер.

### `PACE_QUICK_CHIPS` — L206
Exported const: быстрые чипы темпа ['5:00'…'8:00'].

## `src/components/Onboarding/StepAssessment.jsx` (153 строки)
Шаг «AI-оценка цели»: вердикт + предложения-кнопки + VDOT + прогнозы по дистанциям + тренировочные темпы. Данные из api.assessGoal (фетчит родитель OnboardingFlow).

### `PACE_BARS`/`PACE_LABELS`/`DIST_LABELS` — L8–15
Const: цвета/лейблы зон темпа и дистанций.

### `buildBasis(formData)` — L18
Текст «на чём построен прогноз»: последний результат либо комфортный темп, иначе null.

### `VERDICT` — L29
Const: realistic/challenging/caution/unrealistic → {cls, title, Icon}.

### `StepAssessment({formData, assessment, loading, onApplySuggestion})` — L37
Компонент (default export). Состояния: подсказка какие поля заполнить / спиннер «Оцениваем цель...» / результат: вердикт-карточка с messages и suggestions (кнопка «применить» → onApplySuggestion(field, value)), VDOT с источником, строка basis, прогнозы по дистанциям, грид тренировочных темпов (easy/marathon/threshold/interval). API сам не вызывает.

## `src/components/Onboarding/StepGenerating.jsx` (51 строка)
Финальный экран онбординга: «Собираю твой план» (ai) или «Календарь готов» (self). Чисто презентационный — генерация в очереди на бэке.

### `GEN_STEPS` — L8
Const: 4 статичных шага генерации (done/active/todo) для анимации.

### `StepGenerating({isPlanMode, planMessage, onDone})` — L15
Компонент (default export). Для isPlanMode: AI-марка с кольцом, прогресс-бар, список GEN_STEPS; иначе чек «Календарь готов». CTA → onDone (дашборд/календарь).

## `src/components/Onboarding/StepGoal.jsx` (241 строка)
Шаг «Цель» (не для self): выбор цели + условные поля (гонка / похудение / здоровье) + дата старта.

### `ICONS` — L12
Const: iconKey → компонент иконки.

### `tomorrow()`/`inFourWeeks()`/`today()` — L17–19
Const-стрелки: даты-минимумы для date-инпутов (YYYY-MM-DD).

### `assessWeightLoss({currentKg, targetKg, dateStr})` — L26
Клиентская оценка темпа похудения (без round-trip): % массы тела/нед; ≤1% ok, 1–1.5% warn, >1.5% bad; цель ≥ текущего веса → warn. Возвращает {kind, rate, text} или null.

### `StepGoal({formData, onChange, eyebrow})` — L51
Компонент (default export). Radiogroup из GOALS; для race/time_improvement — сегменты дистанции, дата забега (min завтра), целевое время с маской `formatDurationMask`/`normalizeDuration`; для weight_loss — текущий/целевой вес + дата (min +4 нед) + вердикт assessWeightLoss; для health — программы HEALTH_PROGRAMS и срок custom; для любой цели — обязательная training_start_date. Все изменения через onChange(field, value).

## `src/components/Onboarding/StepMode.jsx` (84 строки)
Шаг «Режим тренировок»: AI (рекомендуем) / Сам / Живой тренер (disabled «СКОРО»).

### `MODES` — L8
Const: 3 режима с Icon, sub, features; coach помечен soon.

### `StepMode({formData, onChange, eyebrow})` — L35
Компонент (default export). Карточки-кнопки режимов; активная раскрывает список фич; soon — disabled; выбор → onChange('training_mode', id).
