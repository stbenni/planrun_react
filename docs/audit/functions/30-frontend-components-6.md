# Frontend components 6/6 (Stats v3, WorkoutShare, Trainers) — справочник

## `src/components/Stats/v3/blocks.jsx` (594 строки)
Библиотека presentational-блоков для статистики v3 (вкладки Обзор/Тренды/Рекорды/Достижения и десктоп-версия): переключатели, hero-объём, мини-карточки, графики трендов и нагрузки (CTL/ATL/TSB), рекорды, прогнозы, ачивки. Данные получает уже обработанными из `statsV3Utils` через пропсы.

### `SPORTS` — L7
Экспортируемый const: список фильтров вида активности (all/run/walk/ofp/sbu) с label и типом для иконки.

### `PERIODS` — L14
Экспортируемый const: пары [id, label] периодов (week/month/quarter/year).

### `fmtPaceSec(sec)` — L18
Форматирует темп из секунд в `м:сс`, при пустом значении возвращает «—».

### `fmtTipDate(iso)` — L24
Дата ISO `YYYY-MM-DD` → «5 июн» (короткий русский месяц из `MONTHS_SHORT`).

### `clamp01(x)` — L30
Ограничивает число диапазоном [0, 1] (для расчёта hover-индекса на графиках).

### `SportSwitch({sport, setSport})` — L32
Таблист-переключатель вида активности по `SPORTS`; рендерит кнопки с `ActivityTypeIcon`/`ActivityIcon`, активную помечает `is-active`.

### `PeriodSeg({period, setPeriod})` — L53
Сегмент-переключатель периода по `PERIODS` (role=tablist).

### `MiniCard({label, value, unit})` — L72
Мини-карточка метрики: подпись + число + опциональная единица.

### `MiniRow({d})` — L84
Ряд из трёх `MiniCard` (время через `formatHoursMinutes`, число тренировок, средний темп) из обработанных данных `d`.

### `HeroVolume({d, period, rightSlot})` — L94
Hero-блок «Объём за период»: крупная дистанция, дельта к прошлому периоду (стрелка ↑/↓), бар-чарт `d.series` с подсветкой текущего бакета, подписи start/end и три суб-метрики (средн./макс. на бакет, прошлый период). `rightSlot` (десктоп) заменяет блок дельты справа и переносит дельту в inline-вид. При `!d.hasData` — заглушка.

### `ActivityChart({d})` — L161
Карточка «График активности»: либо heat-сетка (`d.useHeat`, класс `is-{v}` на ячейку), либо вертикальные бары `d.series` с подсветкой текущего.

### `fmtRecentDate(w)` — L187
Дата тренировки (start_time или date) → «5 июн.» через `toLocaleDateString('ru-RU')`.

### `RecentList({recent, onWorkoutClick, onShare})` — L195
Карточка «Последние тренировки»: до 8 строк со сворачиванием (`useState showAll`), кнопка «↗ Поделиться» для последней тренировки (если передан `onShare`). Каждая строка — кнопка с иконкой типа (`typeColorVar`/`typeLabel` из calV3), датой и правой частью: для ОФП (`type === 'other'`) — время/пульс по наличию, иначе км + темп + пульс.

### `TrendCard({m})` — L269
SVG-линейный график одной метрики тренда (объект `m` из `processTrendsV3`): area-градиент, dashed-линия цели `m.goal`, точки на каждом значении, hover/touch-курсор с тултипом (дата недели + значение, темп форматируется `fmtPaceSec`), шапка с цветной точкой/значением/дельтой и подписи оси X. Хендлер `move` пересчитывает индекс hover из координаты курсора.

### `tsbStatus(tsb)` — L359
Маппинг значения TSB в статус формы: «Свежий»/«Норма»/«Усталость»/«Перегруз» с цветом и подписью.

### `LoadChart({load})` — L366 (не экспортируется)
SVG-график трёх линий CTL/ATL/TSB c нулевой осью, hover/touch-курсором и тултипом со значениями всех трёх линий на выбранной дате.

### `Legend({color, label, value})` — L426 (не экспортируется)
Строка легенды: цветная черта + подпись + значение.

### `LoadCard({load, headLabel})` — L436
Карточка «Форма и нагрузка»: при `!load.available` — заглушка «нужно ≥7 дней»; иначе hero-TSB с цветным статусом (`tsbStatus`), `LoadChart` и три `Legend` (CTL/ATL/TSB).

### `fmtTime(sec)` — L481
Секунды → `ч:мм:сс` или `мм:сс` для времени рекордов.

### `fmtPrDate(iso)` — L489
ISO-дата → «5 июн» для подписи рекорда.

### `isFresh(iso)` — L495
true, если дата рекорда не старше 14 дней (бейдж «★ НОВЫЙ»).

### `PrGrid({records, compact})` — L501
Сетка личных рекордов по `PR_DISTS` (5к/10к/21.1/42.2): время (`fmtTime`), VDOT (не в compact), дата, бейдж свежести; пустые дистанции — `is-empty`.

### `PredCard({pred})` — L524
Карточка «Прогноз по VDOT»: список `PRED_DISTS` с временем `formatted` и темпом `pace_formatted` из `pred.predictions`; без данных — заглушка.

### `PointsHero({ach})` — L554
Hero-блок достижений: 🏆, очки, уровень, прогресс-бар до следующего уровня (`ach.progressPct`, `ach.pointsToNext`).

### `AchCategory({c})` — L582
Карточка категории ачивок: заголовок, счётчик полученных, сетка `StaHexBadge`.

## `src/components/Stats/v3/OverviewTabV3.jsx` (25 строк)
Вкладка «Обзор» статистики v3 (мобайл): собирает блоки из `blocks.jsx` на данных `processOverviewV3`.

### `OverviewTabV3({rawData, sport, setSport, period, setPeriod, onWorkoutClick})` — L7
Default-export. `useMemo(processOverviewV3(workoutsList, plan, period, sport))` → рендерит `SportSwitch`, `PeriodSeg`, `HeroVolume`, `MiniRow`, `ActivityChart`, `RecentList` (onShare = onWorkoutClick).

## `src/components/Stats/v3/RecordsTabV3.jsx` (39 строк)
Вкладка «Рекорды»: грузит личные рекорды и прогноз с API, отдаёт их в `PrGrid`/`PredCard`.

### `RecordsTabV3({api, viewContext})` — L4
Default-export. useEffect с cancel-флагом: `api.getPersonalRecords()` → словарь по `distance_label` в state `records`; `api.getRacePrediction(viewContext)` → state `pred`. Рендер: подзаголовок + `PrGrid` + `PredCard`.

## `src/components/Stats/v3/StaHexBadge.jsx` (16 строк)
Шестиугольный бейдж достижения.

### `StaHexBadge({b, size})` — L3
Default-export. Hex через `clip-path` (`HEX_CLIP` L1), tier-класс, иконка `b.ic`, звезда свежести `b.fresh`, процент прогресса для неполученных, подпись `b.title`.

## `src/components/Stats/v3/StatsDesktopV3.jsx` (101 строка)
Десктоп-раскладка статистики v3 (две колонки): объём + форма + последние тренировки слева, рекорды/тренды/ачивки справа.

### `StatsDesktopV3({api, viewContext, rawData, sport, setSport, period, setPeriod, onWorkoutClick})` — L9
Default-export. useEffect с cancel-флагом грузит `api.getTrainingLoad(viewContext, 90)` → `processLoadV3`, `api.getRacePrediction` (только vdot), `api.getPersonalRecords` (словарь по `distance_label`). useMemo: `processOverviewV3`, `processTrendsV3(…, 'run', 12)`, `computeAchievements({workoutsList, vdot, records})` (до 8 полученных бейджей). Рендер: `SportSwitch`+`PeriodSeg` сверху; main — `HeroVolume` (rightSlot = inline `MiniCard`-ряд), `LoadCard`, `RecentList`; aside — `PrGrid compact`, список `TrendCard`, сетка `StaHexBadge`.

## `src/components/Stats/v3/StatsV3.jsx` (105 строк)
Корневой компонент статистики v3: на десктопе (≥1024px) делегирует в `StatsDesktopV3`, на мобиле — 4 свайпабельные вкладки.

### `useIsDesktop()` — L18 (не экспортируется)
Хук matchMedia `(min-width: 1024px)` с подпиской на change; SSR-safe начальное значение.

### `StatsV3({api, viewContext, rawData, user, onWorkoutClick, initialTab})` — L33
Default-export. State: tab (валидация `initialTab` по `TAB_IDS`), sport, period. Хук `useSwipeableTabs` (свайпы между вкладками, выключен на десктопе, ignoreSelector для heatmap/инпутов). Рендер мобайл: заголовок, таблист `TABS` (Обзор/Рекорды/Тренды/Достижения), панель с активной вкладкой (`OverviewTabV3` | `RecordsTabV3` | `TrendsTabV3` | `AchievementsTabV3`).

## `src/components/Stats/v3/TrendsTabV3.jsx` (36 строк)
Вкладка «Тренды»: графики метрик + карточка формы/нагрузки.

### `TrendsTabV3({api, viewContext, rawData})` — L5
Default-export. useEffect: `api.getTrainingLoad(viewContext, 90)` → `processLoadV3` в state. useMemo: `processTrendsV3(workoutsList, 'run', 12)`. Рендер: список `TrendCard` (или заглушка) + `LoadCard`.

## `src/components/Stats/WeeklyProgressChart.jsx` (47 строк)
Легаси-график недельного прогресса (горизонтальные бары по неделям). Экспортируется только через barrel `Stats/index.js`, прямых потребителей нет — кандидат в мёртвый код.

### `WeeklyProgressChart({data})` — L7
Default-export. Группирует дневные точки `{distance, workouts}` чанками по 7, считает сумму дистанции/тренировок на неделю и рендерит бары шириной относительно максимума; при пустых данных — заглушка.

## `src/components/Stats/WorkoutDetailsModal.jsx` (525 строк)
Модалка деталей выполненной тренировки в стиле Garmin: карта маршрута + вкладки Обзор/Данные/Круги/Графики, шаринг через `ShareComposer`, удаление тренировки.

### `RouteMap` — L30 (lazy const)
Ленивая загрузка карты: `MapboxRouteMap` при наличии `VITE_MAPBOX_TOKEN`, иначе `LeafletRouteMap`.

### `matchesSelectedWorkout(workout, selectedWorkoutId)` — L36
Сопоставляет тренировку с выбранным id; id вида `log_<n>` означает ручную запись (`is_manual`).

### `getLapLabel(lap)` — L49
Имя круга: пользовательское название либо «Круг N» для generic-имён (`GENERIC_LAP_NAME_RE`).

### `getLapTableLabel(lap, fallbackIndex)` — L57
Для таблицы кругов сокращает generic-«Круг N» до номера N.

### `detectIntervalPattern(laps)` — L66
Эвристика распознавания интервальной тренировки по кругам: фильтрует круги-кандидаты (0.15–2.5 км, 30–1200 с, валидный темп), ищет пары «быстрый → восстановление» (темп следующего ≥1.12× и ≥+18 с/км, дистанция восстановления в пределах нормы); при ≥2 парах возвращает `{isLikelyInterval, rolesByLapIndex (work/recovery), pairCount}` для подсветки строк таблицы.

### `ExerciseList({items})` — L111 (не экспортируется)
Список ОФП/СБУ-упражнений с чипами подходы×повторы/вес/длительность; переиспользует классы `WorkoutCard.css`, чтобы совпадать с плановой карточкой.

### `WorkoutDetailsModal({isOpen, onClose, date, dayData, loading, onEdit, onDelete, selectedWorkoutId})` — L138
Default-export. Сторы: `useAuthStore` (api), `useWorkoutRefreshStore.getState().triggerRefresh()` после удаления. State: deleting, activeTab, timelineHoverIndex, timelineData/lapsData/loadingTimeline (по workoutId, кэш в `loadedWorkoutsRef`), composerOpen. `displayedWorkouts` — фильтр по `matchesSelectedWorkout` с fallback на все. useEffect грузит `api.getWorkoutTimeline(id)` для неручных тренировок (timeline + laps), сбрасывает кэш при закрытии. `handleDeleteWorkout` (L208) — confirm → `api.deleteWorkout(id, is_manual)` → triggerRefresh → onDelete/onClose. Derived: hasGps/hasLaps/hasTimeline, `detectIntervalPattern`, `groupExercisesByCategory(workout.notes)` для ОФП/СБУ, `availableTabs` (details — не для ручных, laps — при кругах, charts — при timeline). Рендер: `Modal` (fullscreen на мобиле) с картой (`RouteMap` в Suspense, hover синхронизирован с графиком), вкладки: Обзор (карточки метрик, упражнения, заметки, кнопки Поделиться/Редактировать/Удалить), Данные (список всех полей + источник через `getSourceLabel`), Круги (таблица с ролями work/recovery, форматтеры из `lapFormat`), Графики (`CombinedWorkoutChart` с onHoverIndex). Снаружи модалки — `ShareComposer`.

## `src/components/Stats/WorkoutShareButton.jsx` (54 строки)
Кнопка «Поделиться» + открытие композера шаринг-карточки. Используется в `Calendar/v3/DayCompletedV3`.

### `WorkoutShareButton({workout, date, timeline, api, className, label, title, children})` — L17
Default-export. Берёт api из `useAuthStore`, если не передан пропом; state open; рендерит кнопку и `ShareComposer` (open/onClose/api/date/workout/timeline). При отсутствии workout/date возвращает null.

## `src/components/Stats/WorkoutShareCard.jsx` (1192 строки)
Легаси DOM-карточка тренировки для захвата html2canvas («Поделиться», 3 варианта дизайна: poster/route/minimal) со встроенными инлайн-стилями, своим SVG-превью маршрута и графиком пульса. Не импортируется нигде в src — текущий шаринг идёт через `Share/ShareComposer` (canvas) + backend `WorkoutShareCardService`; файл — кандидат в мёртвый код.

### `getActivityTypeLabel(type)` — L44
Русский label типа активности из локального словаря `ACTIVITY_TYPE_LABELS` (дублирует `utils/workoutFormUtils`).

### `getWorkoutDisplayType(workout)` — L50
Выбирает отображаемый тип: плановый `type`, если известен словарю, иначе `activity_type` (дублирует `utils/workoutFormUtils`).

### `getSourceLabel(source)` — L60
Label источника импорта (Strava/Garmin/…) из `SOURCE_LABELS` (дублирует `utils/workoutFormUtils`).

### `formatDistanceValue(distanceKm)` — L66
`{value: '5,20', unit: 'км'}` либо null.

### `formatDurationValue(workout)` — L75
Длительность из duration_seconds (`ч:мм:сс`/`мм:сс`) либо duration_minutes (`Xч Yм`).

### `formatDurationText(workout)` — L94
Текстовая длительность («1 ч 5 мин 12 сек»).

### `truncateText(value, maxLength=140)` — L111
Нормализует пробелы и обрезает заметку с «…».

### `getRoutePoints(timeline)` — L119
Извлекает валидные GPS-точки {latitude, longitude} из таймлайна.

### `ShareBadge({children, muted})` — L130
Pill-бейдж (оранжевый акцент или muted).

### `BrandWordmark({size, marginBottom})` — L147
Логотип «planRUN» (классы top-header-logo) для карточки.

### `splitMetricValue(value)` — L164
Разбивает значение метрики на число и единицу («/км», «уд/мин», «ккал», «м») по regex-паттернам.

### `ShareMetricTile({label, value, accent, primaryAlign, secondaryAlign})` — L191
Тайл метрики: label-капс + значение, разбитое `splitMetricValue` на primary/secondary с выравниванием.

### `ShareRoutePreview({timeline, staticMapUrl, staticMapAttribution, marginTop, height, elevated})` — L257
Превью маршрута: при `staticMapUrl` — `<img>` статической карты с градиент-оверлеями и чипами «Маршрут»/«GPS»; иначе — собственная SVG-отрисовка: сэмплирование до ~180 точек, проекция bbox в координаты, декоративные «дороги»/сетка, трёхслойная линия маршрута с градиентом и маркеры старт/финиш.

### `ShareHeartRateChart({timeline, marginTop})` — L507
SVG-график пульса по времени (fallback, когда нет маршрута): сэмплирование до ~120 точек, area+line, сетка, мин/средн/макс под графиком.

### `buildShareModel({date, workout, timeline, staticMapUrl, staticMapAttribution})` — L597
Собирает модель карточки: dateStr/startTimeStr, typeLabel, sourceLabel, дистанция/длительность, заметка (`truncateText`), `hasRoute`, массив metrics (время/темп/пульс/набор/калории, только непустые).

### `PosterShareCard({model})` — L641 (не экспортируется)
Вариант «постер»: светлый градиент-фон с радиальными пятнами, шапка (wordmark + бейджи + дата), «Главный результат» (большая дистанция или время) + карточка «Статус Завершено», заметка, маршрут (`ShareRoutePreview`) либо пульс (`ShareHeartRateChart`), сетка `ShareMetricTile`, футер planrun.app + #id.

### `RouteShareCard({model})` — L864 (не экспортируется)
Вариант «маршрут»: оранжевый фон, крупная курсивная дистанция + hero-тайл «Время», маршрут увеличенной высоты (elevated), 3 метрики (темп/пульс/высота, выравнивание вправо), заметка и attribution.

### `MinimalShareCard({model})` — L1066 (не экспортируется)
Вариант «минимал»: белая карточка, дистанция/время, табличный список summaryRows (тип/старт/источник + метрики, максимум 6), заметка. Карта не рендерится.

### `WorkoutShareCard({date, workout, timeline, staticMapUrl, staticMapAttribution, className, variant})` — L1168
Default-export. Строит модель `buildShareModel` и выбирает вариант: 'route' → `RouteShareCard`, 'minimal' → `MinimalShareCard`, иначе `PosterShareCard`. Проп `className` фактически не используется внутренними карточками.

## `src/components/Stats/WorkoutSheet.jsx` (115 строк)
Шит с деталями тренировки (снизу на мобиле, справа на десктопе) через портал в `#modal-root`. Используется в `StatsScreen` и `UserProfileScreen`.

### `OfpContent({workout, exercises, canEdit, onDelete})` — L15 (не экспортируется)
ОФП/СБУ-вид в стиле calv3: тип с цветным акцентом (`typeColorVar`/`typeLabel`), карточка времени (`formatWorkoutDuration`), `ExerciseListV3` либо «Загрузка…»/«Упражнения не записаны», кнопка удаления при canEdit.

### `WorkoutSheet({open, workout, date, api, viewContext, canEdit, onClose, onEdit, onDelete})` — L53
Default-export. `useMediaQuery('(min-width: 1024px)')` для inline-графиков; определяет силовой тип по `STRENGTH_TYPES` без дистанции. useEffect грузит упражнения дня `api.getDay(date, viewContext)` (поле dayExercises/day_exercises) только для силовых; второй useEffect — закрытие по Escape. Рендер через `createPortal`: scrim + шит c grip/крестиком; силовые → `OfpContent`, бег/кардио → `DayCompletedV3` (defaultExpanded, hideToggle, chartsInline на десктопе).

## `src/components/Trainers/ApplyCoachForm.jsx` (617 строк)
5-шаговая анкета «Стать тренером»: специализация → опыт → о себе → сертификации/контакты → стоимость; отправка через `api.applyCoach`. Лениво грузится из `AppTabsContent`.

### `STEP_META` — L46 (const)
Метаданные 5 шагов: title/short/summary/hint/tip + иконка для прогресс-навигации и активной панели.

### `ApplyCoachForm()` — L89
Default-export. Сторы/хуки: `useAuthStore` (user, api), `useNavigate`. State: step/submitting/error/success + поля по шагам (specialization[], experienceYears, runnerAchievements, athleteAchievements, bio, philosophy, certifications, contactsExtra, acceptsNew, pricesOnRequest, pricingItems[]). useEffect-ы: блокировка для `user.role === 'coach'`; автоскролл активного шага и панели при смене step (refs, пропуск первого рендера). Хендлеры: `toggleSpec` — чекбокс специализации; `addPricingItem`/`removePricingItem`/`updatePricingItem` (L153–170) — управление тарифами, при смене типа ≠ custom подставляет label из `PRICING_TYPES`; `validateStep` (L172) — шаг 1: ≥1 специализация, шаг 2: опыт 1–50 лет, шаг 3: bio 100–500 символов; `nextStep`/`prevStep`; `handleSubmit` (L199) — собирает payload (pricing с parseFloat либо [] при pricesOnRequest) и вызывает `api.applyCoach`, по успеху state success. Рендер: success-экран и экран «уже тренер» (ранние возвраты); иначе шапка, journey-карточка с прогресс-баром и кликабельными пройденными шагами, панель активного шага (5 условных блоков: чипы специализаций, числовой ввод + textarea опыта, bio со счётчиком, сертификации/контакты/чекбокс, конструктор тарифов с PRICING_TYPES/PRICING_PERIODS) и навигация Назад/Далее/Отправить.
