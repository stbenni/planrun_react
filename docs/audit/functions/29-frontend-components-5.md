# Frontend components 5/6 (Onboarding ч.2, Share, Stats ч.1) — справочник

## `src/components/Onboarding/StepProfile.jsx` (431 строка)
Шаг «Профиль» онбординга: для режима self — минимум (имя, дата старта, пол), для AI — полный профиль бегуна (опыт, объём, история забегов, дни тренировок, ОФП, здоровье).

### `EXP_ICONS` — L17
Константа-словарь iconKey → компонент иконки уровня опыта (LeafIcon…TrophyIcon).

### `today()` — L18
Возвращает сегодняшнюю дату строкой `YYYY-MM-DD` (min для date-инпута).

### `StepProfile({formData, onChange, onToggleArray, eyebrow})` — L20 (default export)
Пропсы: `formData` (объект формы онбординга), `onChange(key, value)`, `onToggleArray(key, item, on)`, `eyebrow` (надзаголовок). Рендерит поля имени/фамилии, дату старта (self), пол; для не-self — год рождения/рост/вес, уровень опыта (EXPERIENCE_LEVELS), диапазон недельного объёма (WEEKLY_VOLUME_RANGES), расширенный блок для race/time_improvement (развилка «бегал на забеге?»: комфортный темп с PACE_QUICK_CHIPS либо последний результат с маской длительности), дни бега/ОФП (DAY_LABELS), ОФП-переключатель и предпочтения (OFP_PREFERENCES), время тренировок (TRAINING_TIMES), дорожку, заметки о здоровье. Использует утилиты `formatPaceMask`/`paceMaskToSeconds` (paceMask), `formatDurationMask`/`normalizeDuration` (durationMask), константы из `./onboardingForm`. Сторов/API нет — чистый контролируемый компонент.
Значимые внутренние хендлеры: `setPace` — маска темпа + расчёт easy_pace_sec (180–600с); `setRaceHistory` — развилка источника VDOT, чистит поля противоположной ветки и выводит is_first_race_at_distance; `setLastRaceDistance` — авто-вывод is_first_race_at_distance из сравнения с целевой дистанцией; `selectVolumeRange` — выбор диапазона, weekly_base_km = середина, для верхних диапазонов открывает точный ввод.

## `src/components/ParticlesBackground.jsx` (93 строки)
Canvas-фон с плавающими оранжевыми точками (без линий) для лендинга: requestAnimationFrame, wrap по краям, ResizeObserver.

### `rand(min, max)` — L8
Случайное число в диапазоне.

### `ParticlesBackground({className, isDark})` — L10 (default export)
Пропсы: `className`, `isDark` (по умолчанию true; в светлой теме непрозрачность точек ×2). Рендерит абсолютный `<canvas aria-hidden>`. В useEffect: `resize` пересоздаёт массив частиц (~1 на 10000px², DPR-aware), `draw` анимирует движение с wrap; очистка отменяет rAF и отключает ResizeObserver. Сторов/API нет.

## `src/components/RegisterModal.jsx` (44 строки)
Модалка минимальной регистрации на лендинге (логин/email/пароль), оборачивает RegisterScreen в Modal.

### `RegisterModal({isOpen, onClose, onRegister, returnTo})` — L10 (default export)
Пропсы: `isOpen`, `onClose`, `onRegister` (проброс в RegisterScreen), `returnTo` ({path, state} — куда вернуть после успеха). Рендерит `Modal` (small, hideHeader, disableBackdropClose) с `RegisterScreen embedInModal minimalOnly`. Использует `useNavigate` (react-router): после успеха — navigate на returnTo.path или `/` с `registrationSuccess: true`.

## `src/components/Share/ColorPicker.jsx` (135 строк)
Кастомный HSV-колорпикер для цвета текста шер-карточки: SV-квадрат + hue-полоса + HEX-инпут, drag через pointer-события.

### `clamp(n, min, max)` — L3
Ограничение числа диапазоном.

### `hexToRgb(hex)` — L5
Парсит `#RRGGBB` в {r,g,b}; при невалидном входе возвращает белый.

### `rgbToHex(r, g, b)` — L12
RGB → строка `#RRGGBB` (uppercase, с клампом каналов).

### `rgbToHsv(r, g, b)` — L17
RGB (0–255) → {h: 0–360, s, v: 0–1}.

### `hsvToRgb(h, s, v)` — L33
HSV → RGB (0–255) по секторам hue.

### `hsvToHex(h, s, v)` — L42
Композиция hsvToRgb + rgbToHex.

### `ColorPicker({value, onChange, onClose})` — L47 (default export)
Пропсы: `value` (hex), `onChange(hex)`, `onClose`. Состояние — hsv и текст HEX-поля. Рендерит SV-градиентную область с бегунком, hue-полосу, превью, HEX-инпут и кнопку «Готово». Значимые внутренние: `emit` — синхронизирует hsv/hex и зовёт onChange; `moveSV`/`moveHue` — пересчёт s/v/h из координат указателя; `startDrag` — pointermove/pointerup-подписка на window; `onHexInput` — валидация и применение введённого HEX. Сторов/API нет.

## `src/components/Share/ShareComposer.jsx` (575 строк)
Полноэкранный редактор шер-картинки тренировки на canvas (портал в #modal-root): шаблоны (свайп), фон (градиент/фото/карта), выбор метрик, цвет текста с авто-контрастом, кадрирование фото, экспорт в JPEG (поделиться/сохранить).

### `EXPORT_W` — L17, `ASPECTS` — L18, `ACCENT` — L19, `TEXT_COLORS` — L20, `GRADIENTS` — L21, `TABS` — L35
Константы: ширина экспорта 1080, форматы 9:16/4:5, акцент, палитра текста, 12 пресетов градиентов, вкладки панели (Фон/Данные/Цвет).

### `coverParams(img, cw, ch, zoom)` — L37
Считает размеры отрисовки изображения в режиме cover с учётом zoom.

### `drawGradient(ctx, w, h, stops)` — L43
Заливает canvas линейным градиентом по списку стопов.

### `sampleBrightness(srcCanvas, w)` — L49
Сэмплирует нижнюю половину canvas в 16×16 и возвращает среднюю яркость 0–1 (для авто-скрима).

### `ShareComposer({open, onClose, api, date, workout, timeline})` — L62 (default export)
Пропсы: `open`, `onClose`, `api` (нужен `api.getWorkoutShareMap(id, {width,height,scale})`), `date`, `workout`, `timeline`. Строит модель через `buildShareModel` (utils/shareWorkoutModel); рисует фон (градиент/фото/статичная карта) + скримы + активный шаблон из `TEMPLATES` (shareTemplates). Состояние: aspect, template, bgMode, gradKey, textColor ('auto' = белый + адаптивный скрим), showRoute, selected метрики (1–8), zoom, status, tab, cropOpen, colorPickerOpen. Использует утилиты `pickPhotoDataUrl/loadImage/canvasToBlob/shareImageBlob/saveImageBlob` (utils/shareImage), `tapHaptic`, компонент `ColorPicker`. Значимые внутренние: `draw` — полный рендер кадра (фон → авто-контраст-скримы → шаблон); `scheduleDraw` — rAF-дебаунс; эффект загрузки фоновой карты (L177) и мини-карты для шаблона «Карточка» (L194) через `api.getWorkoutShareMap`; `pickPhoto` — выбор фото + переход в кадрирование с бэкапом состояния; `commitSwipe` — анимированная смена шаблона с ghost-снапшотом; `onPointerDown/Move/Up` — распознавание горизонтального свайпа по канвасу; `drawCrop`/`onCropDown/Move/Up`/`editCrop`/`confirmCrop`/`cancelCrop` — редактор кадрирования с сеткой третей и откатом; `toggleMetric` — выбор метрик (мин 1, макс 8); `handleShare`/`handleSave` — экспорт JPEG 0.92 и share/save; `openPicker`/`closePicker` — анимация колорпикера.

## `src/components/Share/shareTemplates.js` (404 строки)
Canvas-шаблоны оверлея шер-карточки (фон рисует композер): «Минимал», «Спорт», «Карточка», «Трек» + общие примитивы рисования (лого, эйбрау, герой-число, стат-полосы, трек маршрута).

### `BRAND` — L5, `FF` — L6, `FF_LOGO` — L7, `MUTED` — L8, `HAIRLINE` — L9, `PAD` — L10
Константы стиля: брендовый оранжевый, шрифтовые стеки (Montserrat / Jost для лого), приглушённый белый, цвет волосяных линий, отступ 60px.

### `roundRectPath(ctx, x, y, w, h, r)` — L12 (export)
Строит path скруглённого прямоугольника через arcTo (полифилл roundRect).

### `projectRoute(points, box)` — L23 (export)
Проецирует GPS-точки {lat,lng} в координаты бокса: сэмплирует до ~260 точек, вписывает bbox с сохранением пропорций и центрированием.

### `shadow(ctx, on)` — L43
Включает/выключает тень текста/линий.

### `track(ctx, v)` — L49
Безопасно ставит letterSpacing (try/catch для старых WebView).

### `drawLogo(ctx, w)` — L52
Лого planRUN крупно (87px, курсив Jost) в правом верхнем углу.

### `drawLogoBottom(ctx, w, h)` — L69
Лого компактно (54px) в левом нижнем углу.

### `drawEyebrow(ctx, model, x, topY, accent, color)` — L84
Эйбрау: акцентная плашка 64×6 + «ТИП · ДАТА» с трекингом.

### `drawHero(ctx, metric, x, baseline, color, accent, maxSize, availW)` — L105
Гигантское герой-число с юнитом; авто-уменьшение размера шрифта (шаг 12px, мин 70) под доступную ширину.

### `drawStatsStrip(ctx, metrics, x, labelY, totalW, color, maxCols)` — L129
Горизонтальный ряд до maxCols метрик (label/value/unit) с вертикальными делителями.

### `drawHairline(ctx, x, y, w2)` — L164
Горизонтальная волосяная линия с тенью.

### `drawTrace(ctx, opts, box, lineW)` — L172
Рисует линию маршрута (projectRoute) + стартовую точку-кружок; возвращает спроецированные точки или null.

### `tmplMinimal(ctx, w, h, opts)` — L193
Шаблон «Минимал»: эйбрау + герой-метрика 280px + линия + полоса из 3 остальных метрик + лого сверху.

### `tmplTrace(ctx, w, h, opts)` — L210
Шаблон «Трек»: маршрут в верхней трети с подписью дистанции, внизу эйбрау + полоса до 4 метрик.

### `drawMetricGrid(ctx, list, x, y, totalW, color, cols, rowH)` — L234
Сетка метрик cols×N (label сверху, value снизу) для шаблона «Карточка».

### `tmplCard(ctx, w, h, opts)` — L256
Шаблон «Карточка»: glass-панель (blur(28px) самого канваса + полупрозрачная заливка + обводка), внутри пилюля типа + дата, опциональный баннер карты (routeMapImg), герой-метрика и сетка остальных.

### `tmplSport(ctx, w, h, opts)` — L330
Шаблон «Спорт»: до 5 метрик плотной стопкой слева (первая на 32% крупнее), горизонтальное сжатие 0.86 под «узкий гротеск», авто-подбор размера под ширину; тип/дата под стопкой, лого внизу, опциональный мини-трек справа-снизу (showRoute).

### `TEMPLATES` — L397 (export)
Реестр шаблонов {label, draw, needsRoute} по ключам minimal/sport/card/trace.

### `TEMPLATE_ORDER` — L404 (export)
Порядок шаблонов для свайпа: minimal → sport → card → trace.

## `src/components/Stats/AchievementCard.jsx` (20 строк)
Простая карточка достижения (легаси-вариант для списков достижений).

### `AchievementCard({icon, Icon, title, description, achieved})` — L7 (default export)
Рендерит карточку с иконкой (компонент Icon приоритетнее строки icon), заголовком, описанием и бейджем «✓» при achieved. Без сторов/API. Потребителей кроме барреля `Stats/index.js` нет — вероятно мёртвый.

## `src/components/Stats/ActivityHeatmap.jsx` (335 строк)
Heatmap-календарь активности по месяцам (мобильный вариант): свайп/стрелки между месяцами, интенсивность по дистанции, tooltip по дню, легенда и сводка.

### `ActivityHeatmap({data})` — L8 (default export)
Проп: `data` — массив дней `{date, dateLabel, distance, workouts}`. Группирует дни по месяцам (dataMap + monthsData), строит сетку месяца с понедельника (`getMonthCalendar`), слайдер месяцев через translateX. Интенсивность ячейки = distance/maxDistance → opacity 0.3–1. Без сторов/API. Значимые внутренние: `handleTouchStart/Move/End` — распознавание горизонтального свайпа (порог 50px) с preventDefault; `getMonthCalendar(year, month)` — массив ячеек с офсетом под Пн-первый; `handleDayClick` — позиционирование tooltip относительно контейнера. Замечание: ранний `return` при пустых данных стоит ДО `useEffect` (L54) — нарушение правил хуков при смене пустые/непустые данные. Потребителей кроме барреля нет — вероятно мёртвый.

## `src/components/Stats/CombinedWorkoutChart.jsx` (592 строки)
Совмещённый SVG-график темпа (левая ось, выше = быстрее) и пульса (правая ось) по времени тренировки, с прореживанием до 500 точек, сглаживанием темпа, tooltip и синхронизацией hover с картой.

### `parsePaceToSeconds(pace)` — L11
Парсит строку «М:СС» в секунды; null при невалидном.

### `formatPaceFromSeconds(seconds)` — L22
Секунды → «М:СС» (или «—»).

### `formatSpeedFromPace(paceSeconds)` — L28
Темп → скорость км/ч с одним знаком.

### `formatTime(timestamp)` — L33
Timestamp → «ЧЧ:ММ:СС».

### `clamp(value, min, max)` — L41
Ограничение диапазоном.

### `percentile(sortedValues, ratio)` — L43
Линейно-интерполированный перцентиль отсортированного массива.

### `getPaceDomain(paceValues)` — L53
Робастный домен оси темпа: при ≥8 точках режет выбросы по IQR-заборам + p10/p90 + капы от медианы, минимальный диапазон 60с, паддинг 8%; возвращает min/max/adjusted*/hasPaceOutliers.

### `getPaceSmoothingWindowMs(durationMinutes)` — L117
Окно сглаживания темпа от длительности: 15/20/30/45 секунд.

### `getTrimmedMean(values)` — L124
Усечённое среднее (отбрасывает по 20% хвостов при ≥5 значениях).

### `smoothPaceData(points, durationMinutes)` — L133
Для каждой точки собирает значения темпа в центрированном временном окне и пишет smoothedPaceSeconds (trimmed mean).

### `buildLinePath(points, xScale, yScale, valueKey)` — L167
Строит SVG-path «M/L» по точкам с конечным значением valueKey.

### `CombinedWorkoutChart({timeline, onHoverIndex})` — L176 (default export)
Пропсы: `timeline` (точки {timestamp, pace, heart_rate}), `onHoverIndex(index|null)` — синхронизация с картой маршрута. useMemo строит chartData (прореживание, сглаживание, домены темпа/пульса). Рендерит SVG 800×280: сетка, две оси с метками, area+линия темпа, линия пульса, x-метки с интервалом 5–30 мин и фильтром ≥80px, crosshair-маркеры и HTML-tooltip (темп/скорость/сырая точка/пульс), легенда с мин/макс/средними. Хук `useMediaQuery` (preserveAspectRatio='none' на мобильном). Значимые внутренние: `getPointFromX` — ближайшая по времени точка из координаты курсора; `handlePointerMove` — позиционирование tooltip с клампом по краям обёртки. Используется в WorkoutDetailsModal и Calendar/v3/DayCompletedV3.

## `src/components/Stats/DistanceChart.jsx` (166 строк)
Столбчатый график дистанции по дням (десктоп): бары, точки-маркеры количества тренировок, выборочные подписи дат, tooltip, сводка.

### `DistanceChart({data})` — L7 (default export)
Проп: `data` — массив `{date, dateLabel, distance, workouts}`. Рендерит Y-ось (5 делений), бары высотой по maxDistance, до 3 точек тренировок на бар, подписи только для ~6 ключевых дат, tooltip при hover, сводку (средняя дистанция, всего тренировок). Без сторов/API. Потребителей кроме барреля нет — вероятно мёртвый.

## `src/components/Stats/HeartRateChart.jsx` (382 строки)
Одиночный SVG-график пульса по времени с прореживанием до 500 точек, tooltip и hover-синхронизацией (предшественник CombinedWorkoutChart).

### `HeartRateChart({timeline, hideTitle, onHoverIndex})` — L9 (default export)
Пропсы: `timeline`, `hideTitle`, `onHoverIndex`. useMemo фильтрует точки с heart_rate, прореживает, считает min/max с паддингом 5%. Рендерит SVG 800×250: сетка, оси, area+линия пульса (продлевается до правого края), интервал X-меток 5–30 мин с фильтром ≥80px, crosshair и tooltip (fixed-координаты от viewport), легенда мин/макс/средний. Хук `useMediaQuery`. Значимые внутренние: `getValueFromX`, `handleMouseMove`. Потребителей кроме барреля нет (WorkoutShareCard содержит собственный ShareHeartRateChart) — вероятно мёртвый.

## `src/components/Stats/index.js` (29 строк)
Баррель Stats: реэкспорт графиков, списков, модалки и утилит. Сам баррель никем не импортируется (все потребители ходят прямыми путями) — фактически мёртвый файл.

Реэкспорты: `ActivityHeatmap` L9, `DistanceChart` L10, `WeeklyProgressChart` L11, `HeartRateChart` L12, `PaceChart` L13, `CombinedWorkoutChart` L14, `RecentWorkoutsList` L17, `AchievementCard` L18, `WorkoutDetailsModal` L21, `getDaysFromRange`/`formatDateStr`/`formatPace`/`processStatsData` L24-29.

## `src/components/Stats/LeafletRouteMap.jsx` (172 строки)
Карта маршрута тренировки на Leaflet (растровые OSM/Carto тайлы): fallback-вариант когда нет Mapbox-токена; skeleton до загрузки тайлов, авто-смена тайлов под тему, hover-маркер.

### `TILE_LIGHT` — L5, `TILE_DARK` — L6
URL-шаблоны тайлов: OSM (светлая) / Carto dark_all (тёмная).

### `isDarkTheme()` — L8
Читает `data-theme === 'dark'` с documentElement.

### `LeafletRouteMap({timeline, hoverIndex})` — L18 (default export)
Пропсы: `timeline` (точки с latitude/longitude), `hoverIndex` (индекс timeline для синхронизации с графиком). useMemo строит coords ([lat,lng]) и карту indexToCoord. Эффект инициализации (L39): создаёт L.map (без скролл-зума), tileLayer под тему, polyline #FF4500, start/end divIcon-маркеры, fitBounds−1 зум; MutationObserver на data-theme меняет URL тайлов; cleanup сносит карту. Эффект hover (L116): пересоздаёт hover-маркер по hoverIndex (при отсутствии точной координаты — ближайший индекс). Рендерит обёртку со skeleton до tilesLoaded. Используется через lazy в WorkoutDetailsModal и DayCompletedV3 (когда нет VITE_MAPBOX_TOKEN).

## `src/components/Stats/MapboxRouteMap.jsx` (186 строк)
Векторная карта маршрута на Mapbox GL JS — drop-in замена LeafletRouteMap (тот же API и CSS-классы) при наличии VITE_MAPBOX_TOKEN; стиль streets-v12/dark-v11 под тему.

### `TOKEN` — L5, `STYLE_LIGHT` — L6, `STYLE_DARK` — L7, `ROUTE_SRC` — L8, `ROUTE_LAYER` — L9
Константы: токен из env, URL стилей, id geojson-источника и линии маршрута.

### `isDarkTheme()` — L11
Читает `data-theme === 'dark'`.

### `styleForTheme()` — L15
Возвращает URL стиля под текущую тему.

### `markerEl(cls)` — L19
Создаёт DOM-элемент маркера с классом и вложенным div (общие CSS-классы route-marker-*).

### `MapboxRouteMap({timeline, hoverIndex})` — L32 (default export)
Пропсы как у LeafletRouteMap. useMemo строит coords ([lng,lat]!) и indexToCoord. Эффект инициализации (L52): mapboxgl.Map (mercator, без вращения/наклона), NavigationControl, geojson-линия #FF4500 (addRoute на style.load/load), fitRoute + ResizeObserver, start/end-маркеры, MutationObserver на смену темы (setStyle). Эффект hover (L134): создаёт/перемещает hover-маркер (в отличие от Leaflet-версии — переиспользует маркер через setLngLat). Skeleton до события load. Используется через lazy в WorkoutDetailsModal и DayCompletedV3 (при наличии токена).

## `src/components/Stats/MapLoadingSkeleton.jsx` (18 строк)
Заглушка на время загрузки lazy-чанка карты (Suspense fallback).

### `MapLoadingSkeleton()` — L8 (default export)
Без пропсов; рендерит div с pin-иконкой и текстом «Загрузка карты…», aria-busy/aria-live.

## `src/components/Stats/PaceChart.jsx` (502 строки)
Одиночный SVG-график темпа по времени (выше = быстрее) — предшественник CombinedWorkoutChart; та же математика домена/сглаживания продублирована.

### `formatPaceFromSeconds(seconds)` — L10
Секунды → «М:СС» (без обработки «—»).

### `clamp(value, min, max)` — L16
Ограничение диапазоном (дубль CombinedWorkoutChart L41).

### `percentile(sortedValues, ratio)` — L18
Дубль CombinedWorkoutChart L43.

### `getPaceDomain(paceValues)` — L28
Дубль робастного домена темпа из CombinedWorkoutChart L53 (без hasPaceOutliers, поле adjustedRange вместо adjustedPaceRange).

### `getPaceSmoothingWindowMs(durationMinutes)` — L80
Дубль CombinedWorkoutChart L117.

### `getTrimmedMean(values)` — L87
Дубль CombinedWorkoutChart L124.

### `smoothPaceData(points, durationMinutes)` — L96
Дубль CombinedWorkoutChart L133 (без проверки Number.isFinite по точкам).

### `PaceChart({timeline, onHoverIndex})` — L126 (default export)
Парсит pace «М:СС», прореживает до 500 точек, сглаживает, считает домен; рендерит SVG 800×250 с сеткой/осями/area/линией/crosshair/tooltip (показывает сырое значение точки при расхождении ≥15с) и легендой мин/макс/средний. Хук `useMediaQuery`. Потребителей кроме барреля нет — вероятно мёртвый.

## `src/components/Stats/RecentWorkoutIcons.jsx` (17 строк)
Реэкспорт иконок активностей из common/Icons для списка последних тренировок.

Реэкспорты (L6-17): `RunningIcon`, `WalkingIcon`, `HikingIcon`, `CyclingIcon`, `SwimmingIcon`, `OtherIcon`, `DistanceIcon`, `TimeIcon`, `PaceIcon`, `ActivityTypeIcon`. Единственный потребитель — RecentWorkoutsList (сам без живых потребителей).

## `src/components/Stats/RecentWorkoutsList.jsx` (122 строки)
Список последних тренировок: тип (иконка + ярлык), дата, метрики (дистанция/время/темп), сворачивание после 10 элементов.

### `TYPE_NAMES` — L9
Словарь activity_type → русский ярлык (running/easy/long/tempo/interval/fartlek → «Бег», sbu → «СБУ» и т.п.).

### `RecentWorkoutsList({workouts, api, onWorkoutClick})` — L25 (default export)
Пропсы: `workouts` (массив тренировок), `api` (принимается, но не используется), `onWorkoutClick`. Рендерит до 10 строк (showAll раскрывает все): тип через ActivityTypeIcon, локализованная дата, дистанция (кроме ОФП), длительность (сек или мин, с часами), темп. Кнопки «Показать все»/«Свернуть». Потребителей кроме барреля нет — вероятно мёртвый.

## `src/components/Stats/statsAchievements.js` (137 строк)
Чистый расчёт достижений: 12 бейджей в 3 категориях (дистанция, постоянство, скорость/форма), очки и уровни (Новичок→Чемпион).

### `DAY_MS` — L1
Миллисекунды в сутках.

### `workoutDateStr(w)` — L3
Дата тренировки: из start_time (до 'T') или поля date.

### `km(w)` — L7
parseFloat(distance_km) с фолбэком 0.

### `weekIndex(dateStr)` — L11
Номер ISO-недели как floor(понедельник_недели / 7 дней).

### `longestRun(sortedUniqueInts)` — L19
Длина максимальной серии подряд идущих целых (для стриков дней/недель).

### `LEVELS` — L31
Пороговые уровни по очкам: 0/100/300/600.

### `badgeDefs({...})` — L38
Декларации бейджей по категориям: {ic, title, tier, pts, dir(up/down/bool), metric, t}.

### `resolveBadge(b)` — L70
Вычисляет got/pct/fresh бейджа по направлению (up: metric≥t; down: 0<metric≤t; bool).

### `computeAchievements({workoutsList, vdot, records})` — L86 (export)
Главная функция: считает суммарную дистанцию/кол-во, стрики дней и недель (longestRun по уникальным датам), лучшие 5к/полумарафон и свежие PR (<14 дней) из records; собирает категории бейджей, totalPoints, уровень и прогресс до следующего. Потребители: AchievementsTabV3, StatsDesktopV3, ProfileV3.

## `src/components/Stats/StatsUtils.js` (273 строки)
Легаси-утилиты статистики: расчёт периодов, форматтеры, агрегатор `processStatsData` поверх getAllWorkoutsSummary (по дням).

### `getDaysFromRange(range)` — L8 (export)
Период → {days, startDate}: last7days (скользящие 7), week (с Пн), month (текущий), quarter (3 мес), year (скользящие 12 мес); default — 30 дней. Используется также из statsV3Utils.

### `formatDateStr(date)` — L56 (export)
Date → «YYYY-MM-DD» без таймзонных сдвигов.

### `formatPace(seconds)` — L66 (export)
Секунды → «М:СС», 0 → «—». Один из нескольких дублирующих форматтеров темпа в проекте.

### `processStatsData(workoutsData, allResults, plan, range, workoutsList)` — L81 (export)
Агрегатор вкладки «Обзор» (легаси): превращает summary-объект {дата: {count, distance,…}} в массив, фильтрует по периоду, считает totalDistance/totalTime/totalWorkouts; средний темп — взвешенный по count парсинг строк «М:СС» с валидацией 120–1200 с/км (отбрасывает числовые «AVG от строк»); chartData по дням; planProgress из weeks_data плана (тренировочные дни vs выполненные результаты); recentWorkouts — из workoutsList (по тренировкам) или из summary (по дням, legacy). Потребители: UserProfileScreen, DashboardStatsWidget, ProfileQuickMetricsWidget.

## `src/components/Stats/statsV3Utils.js` (405 строк)
Утилиты статистики v3 поверх списка отдельных тренировок (getAllWorkoutsList): обзор с серией/теплом/дельтой к прошлому периоду, тренды по неделям (VDOT/темп/объём/пульс), нагрузка CTL/ATL/TSB.

### `NON_RUN` — L3, `WALK_TYPES` — L4, `dayMs` — L5, `DAY_KEYS` — L6
Константы: не-беговые типы, типы ходьбы, мс в сутках, ключи дней недели.

### `matchesSport(activityType, sport)` — L8 (export)
Фильтр по виду спорта: all / walk / ofp / sbu / run (по умолчанию — всё, что не в NON_RUN).

### `bucketUnit(range)` — L17
Единица бакета серии: день/мес/нед.

### `workoutDateStr(w)` — L23
Дубль statsAchievements L3.

### `workoutSeconds(w)` — L28
Длительность в секундах: duration_seconds либо duration_minutes×60.

### `km(w)` — L34
Дубль statsAchievements L7.

### `mondayOf(date)` — L39
Понедельник недели даты (00:00).

### `round1(arr)` — L47
Округление массива до 1 знака.

### `planTypeMap(plan)` — L49
Карта дата → тип тренировочного дня из weeks_data плана (без rest).

### `buildSeries(inRange, range, cutoff, rangeEnd)` — L68
Серия км по бакетам: week — 7 дней (highlight = сегодня), month — недели месяца, year — 12 месяцев, иначе — недели периода; возвращает {series, highlightIdx}.

### `sumKmInRange(workouts, from, to)` — L128
Сумма км тренировок в интервале дат.

### `processOverviewV3(workoutsList, plan, range, sport)` — L139 (export)
Обзор v3: фильтр по спорту и периоду (getDaysFromRange), totals (дистанция/время/кол-во), средний темп = Σсек/Σкм (взвешенный по дистанции — точнее легаси-версии), серия с highlight, avgPerBucket/bestBucket, дельта к предыдущему периоду (deltaPct), chartData по дням + 4-уровневый heat (для week/month), recent с plan_type из planTypeMap. Потребители: OverviewTabV3, StatsDesktopV3, ProfileV3.

### `formatHoursMinutes(totalMinutes)` — L253 (export)
Минуты → «Ч:ММ». Потребитель: v3/blocks.jsx.

### `carryForward(arr)` — L260
Заполнение null предыдущим значением + срез ведущих null (возвращает {data, offset}).

### `vdotEstimate(distanceKm, seconds)` — L269 (export)
Оценка VDOT по формулам Дэниелса (VO2 от скорости / % от времени). Экспортирован, но внешних потребителей нет — используется только внутри processTrendsV3.

### `ymd(date)` — L281
Date → «YYYY-MM-DD» (дубль formatDateStr из StatsUtils).

### `trendXLabels(weeks)` — L288
Подписи оси X тренда: «N нед … сейчас».

### `processTrendsV3(workoutsList, sport, weeks)` — L292 (export)
Тренды за N недель (по умолчанию 12): агрегирует км/сек/пульс по неделям от понедельника, лучший недельный VDOT (по тренировкам ≥2 км); строит до 4 метрик-карточек {key, value, data, delta, good, …}: VDOT (≥2 значений), темп (≥3), объём (≥2 ненулевых), ср. пульс (≥3) с carry-forward пропусков. Потребители: TrendsTabV3, StatsDesktopV3, ProfileV3.

### `processLoadV3(loadData, maxDays)` — L389 (export)
Нормализует ответ API нагрузки в {dates, ctl, atl, tsb, curCtl, curAtl, curTsb} (последние maxDays=30 дней); {available:false} если данных нет. Потребители: TrendsTabV3, StatsDesktopV3.

## `src/components/Stats/v3/AchievementsTabV3.jsx` (42 строки)
Вкладка «Достижения» статистики v3: подгружает VDOT и личные рекорды, считает бейджи и рендерит hero-блок очков + категории.

### `AchievementsTabV3({api, viewContext, rawData})` — L5 (default export)
Пропсы: `api` (методы `getRacePrediction(viewContext)` → vdot, `getPersonalRecords()` → records по distance_label), `viewContext` (контекст просмотра тренером), `rawData` ({workoutsList}). useEffect грузит vdot/records с cancelled-флагом; useMemo считает `computeAchievements`. Рендерит `PointsHero` и `AchCategory` из `./blocks`. Потребитель: StatsV3.jsx.
