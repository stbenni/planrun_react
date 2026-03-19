# Roadmap: лучший сервис генерации беговых планов

Дата: 2026-03-09

## Цель

Построить сервис, который:

- стабильно генерирует реалистичные и безопасные беговые планы;
- уважает выбор пользователя по дням, цели и ограничениям;
- опирается на текущую форму, а не на абстрактную цель;
- использует LLM только там, где нужна гибкость, а не там, где нужна точность;
- может расширяться без взрыва числа промптов.

Главная идея: не делать один "идеальный промпт", а сделать надёжный пайплайн:

1. `TrainingStateBuilder` собирает каноническое состояние спортсмена.
2. `Policy Engine` детерминированно строит рамки плана.
3. LLM заполняет детали тренировок внутри этих рамок.
4. `Validator + Repair` проверяют и чинят ответ модели.

## Что уже есть в проекте

Сильные стороны:

- в [../planrun-backend/controllers/StatsController.php](../planrun-backend/controllers/StatsController.php) уже есть хорошая иерархия источников VDOT;
- в [../planrun-backend/services/StatsService.php](../planrun-backend/services/StatsService.php) уже есть recency-weighted оценка по тренировкам;
- в [../planrun-backend/controllers/WorkoutController.php](../planrun-backend/controllers/WorkoutController.php) уже есть обновление VDOT после контрольной/забега;
- в [../planrun-backend/planrun_ai/plan_normalizer.php](../planrun-backend/planrun_ai/plan_normalizer.php) уже есть post-LLM нормализация структуры.

Ключевой разрыв:

- генерация плана в [../planrun-backend/planrun_ai/prompt_builder.php](../planrun-backend/planrun_ai/prompt_builder.php) всё ещё частично живёт своей логикой `calculatePaceZones()` и не опирается на единый authoritative `training state`.

## Внешние best practices, которые стоит зашить в сервис

### 1. База для здоровья и weight loss

- CDC и WHO рекомендуют взрослым минимум `150` минут умеренной активности в неделю или `75` минут интенсивной, плюс силовую работу минимум `2` дня в неделю.
- Для `65+` нужен тот же аэробный минимум, но ещё и регулярная работа на баланс.
- Для беременности и первого года после родов у здоровых женщин умеренная активность в целом безопасна; если женщина уже регулярно делает интенсивную работу, её можно продолжать, но с адаптацией вместе с врачом.
- Для снижения веса одной физической активности обычно недостаточно: для удержания результата нужен и контроль питания; часто требуется объём активности выше базового минимума.

Следствие для продукта:

- `health` и `weight_loss` нельзя проектировать как урезанный `race-plan`;
- для этих целей важнее регулярность, минуты активности, переносимость нагрузки, силовая работа и adherence, а не только километраж и интервалы.

### 2. VDOT должен отражать текущую форму

- VDOT O2 прямо рекомендует считать VDOT по недавнему и точному результату time trial или race result и тренироваться по `current`, а не по `goal VDOT`.
- Для обновления VDOT разумен шаг примерно раз в `4-6` недель, либо после контрольной/забега.
- Тепловые условия и высота требуют коррекции темпов; даже VDOT O2 отдельно поддерживает pace adjustment для heat/altitude.

Следствие для продукта:

- VDOT нужен как отдельная сущность состояния, а не как побочный расчёт внутри prompt;
- каждому VDOT нужен `source`, `confidence`, `updated_at`, `staleness`;
- темпы нельзя жёстко задавать без учёта погоды, рельефа и текущей готовности.

### 3. Распределение интенсивности

- Современный meta-analysis по polarized training показывает преимущество POL в первую очередь для `VO2peak`, особенно на блоках короче `12` недель и у более сильных спортсменов.
- Для остальных surrogate metrics доказанного превосходства POL над другими моделями нет.

Следствие для продукта:

- не надо догматично вшивать один шаблон `80/20` для всех;
- для новичков и большинства recreational runners базовый режим должен быть `mostly easy`;
- для развитых спортсменов и коротких build-блоков можно чаще включать polarized blocks;
- для длинных циклов и массовых пользователей лучше поддерживать `pyramidal` и `mostly easy + 1-2 quality sessions`, а не одну жёсткую модель.

### 4. Taper

- Систематический обзор 2023 года показал, что для endurance athletes эффективен taper до `21` дня с сокращением объёма примерно на `41-60%` при сохранении интенсивности и частоты.
- Для recreational marathon runners большой real-world анализ показал преимущество более дисциплинированного `2-3` недельного taper, а строгий `3-week taper` давал лучший race-day outcome, чем минимальный taper.

Следствие для продукта:

- taper должен быть отдельной policy, а не просто "последняя неделя полегче";
- для марафона и полумарафона taper должен быть более дисциплинированным и длиннее, чем для `5k/10k`;
- near-race недели должны держать ритм, но снижать объём.

### 5. Силовая работа

- Силовая работа у бегунов улучшает running economy.
- Наиболее убедительная польза показана для тяжёлой силовой, плиометрики и комбинированных методов, но точный формат зависит от уровня спортсмена.

Следствие для продукта:

- силовая должна быть встроенной частью сервиса, а не факультативным текстом;
- для новичков: простая домашняя силовая и устойчивость;
- для опытных: progression к тяжёлой силовой/plyo, если это соответствует профилю и доступу к залу.

### 6. Прогрессия нагрузки

- У строгого правила `10%` нет надёжной доказательной базы как универсальной защиты от травм.
- При возвращении после паузы или костного stress injury лучше работают symptom-guided и walk-run подходы, часто на alternate days.
- Detraining может заметно снижать `VO2max` уже в течение нескольких недель; после паузы нельзя считать спортсмена тем же самым, что до паузы.

Следствие для продукта:

- прогрессия должна зависеть не от одной формулы, а от:
  - последних 2-8 недель объёма;
  - compliance;
  - паузы в тренировках;
  - возраста;
  - заметок о здоровье;
  - признаков возврата после травмы;
  - выбранной цели.

### 7. Fueling и hydration

- Для сессий дольше `60-90` минут рекомендации обычно сходятся к `30-60 g/h` carbohydrate, а для очень длинной endurance работы возможны значения до `90 g/h` при тренировке ЖКТ и индивидуальной переносимости.
- Гидратация должна быть персонализированной; универсальной схемы для всех нет.

Следствие для продукта:

- long runs, race rehearsals и гонки должны получать авто-заметки по питанию и гидратации;
- эти заметки должны зависеть от длительности, жары и уровня спортсмена.

## Принцип архитектуры: не промптами, а policy-модулями

Вместо множества разрозненных prompt-вариантов нужен composable engine.

План должен собираться из осей:

1. `GoalPolicy`
2. `DistancePolicy`
3. `ExperiencePolicy`
4. `ReadinessPolicy`
5. `SchedulePolicy`
6. `SafetyPolicy`
7. `EnvironmentPolicy`

### GoalPolicy

Текущие цели:

- `health`
- `weight_loss`
- `race`
- `time_improvement`

Рекомендуемые подтипы:

- `health.start_running`
- `health.couch_to_5k`
- `health.regular_running`
- `weight_loss.low_impact`
- `weight_loss.run_walk`
- `race.first_time_finish`
- `race.performance`
- `time_improvement.5k`
- `time_improvement.10k`
- `time_improvement.half`
- `time_improvement.marathon`

### Athlete profile вместо взрыва промптов

Отдельными флагами и policy:

- `novice`
- `intermediate`
- `advanced`
- `return_after_break`
- `return_after_injury`
- `older_adult_65_plus`
- `pregnant_or_postpartum`
- `chronic_condition_flag`
- `low_confidence_vdot`
- `low_compliance`

Лучший путь: не делать один prompt на каждый case, а собирать одну структуру состояния и активировать нужные policy-модули.

## Каноническое состояние спортсмена

Нужен единый сервис:

- `TrainingStateBuilder`

Он должен возвращать:

- `goal_type`
- `goal_subtype`
- `race_distance`
- `race_date`
- `race_target_time`
- `experience_level`
- `sessions_per_week`
- `preferred_days`
- `preferred_ofp_days`
- `preferred_long_day`
- `weekly_base_km`
- `avg_weekly_km_4w`
- `peak_weekly_km_8w`
- `compliance_2w`
- `compliance_6w`
- `days_since_last_workout`
- `detraining_state`
- `vdot`
- `vdot_source`
- `vdot_confidence`
- `vdot_updated_at`
- `training_paces`
- `pace_adjustments`
- `current_phase`
- `weeks_to_goal`
- `health_flags`
- `special_population_flags`

## Политика VDOT

### Источники VDOT по приоритету

1. `recent_race_or_control`
2. `recent_weighted_training_results`
3. `easy_pace`
4. `target_time`

### Рекомендуемая логика

- `race/control` моложе `8` недель: основной источник;
- `8-12` недель: использовать, но со снижением confidence;
- если свежего race/control нет: брать weighted results из последних `6-12` недель;
- использовать только беговые сессии в диапазоне примерно `2-25 км`;
- учитывать близость дистанции к цели;
- учитывать тип сессии, если он известен;
- после контрольной или забега сразу предлагать обновить training paces;
- при длинной паузе уменьшать confidence и снижать агрессивность плана.

### Чего не делать

- не строить план по `goal VDOT`;
- не использовать один и тот же VDOT одинаково агрессивно для новичка и опытного;
- не давать интервалы как будто VDOT "точный", если он собран из слабого fallback.

## Policy по целям

### 1. `health`

Primary KPI:

- регулярность;
- переносимость;
- достижение недельного минимума активности;
- привычка;
- отсутствие перегруза.

Правила:

- начинать с объёма, который пользователь реально способен держать;
- бег/ходьба для самых начинающих;
- не более `1` quality-like session в неделю, а чаще вообще без неё;
- обязательно `2` дня силовой/устойчивости в неделю, если это допустимо по UX;
- объём считать по минутам не хуже, чем по километрам.

### 2. `weight_loss`

Primary KPI:

- adherence;
- weekly minutes;
- energy expenditure without excessive fatigue;
- сохранение мышечной массы.

Правила:

- не использовать high-intensity как основу;
- опираться на easy/run-walk + силовую;
- не обещать weight loss только за счёт бега;
- давать питание как guidance, а не как медицинский протокол;
- если высокий вес/низкая подготовка, снижать ударную нагрузку и чаще использовать walk-run.

### 3. `race`

Подтипы:

- first finish
- first distance
- performance

Правила:

- цикл строить от даты гонки назад;
- отдельная policy для `5k`, `10k`, `half`, `marathon`;
- long run, key workouts, race-specific blocks, taper задаются детерминированно;
- у first-time athletes больше объёма на освоение дистанции и меньше "остроты".

### 4. `time_improvement`

Правила:

- если нет race date, строить mesocycle по блокам `base -> threshold/VO2 -> consolidation -> time trial`;
- key metric это improvement against current VDOT, а не произвольная амбиция;
- goal realism должен использовать тот же authoritative VDOT, что и сам план.

## Policy по дистанциям

### `5k`

- выше доля economy/VO2/threshold;
- long run менее критичен;
- чаще можно использовать короткие блоки polarized.

### `10k`

- threshold и economy ключевые;
- long run важен, но не доминирует;
- 1 quality session + 1 secondary quality session часто достаточно.

### `half`

- основа: threshold + long run + устойчивый weekly volume;
- marathon-специфический объём ещё не нужен, но дисциплина длительных уже критична.

### `marathon`

- highest importance у long run, fuel practice, race-pace specificity, disciplined taper;
- обязательно notes по питанию и гидратации;
- training paces и weekly structure должны быть более консервативными при низком confidence VDOT.

## Policy по readiness

Нужны классы готовности:

- `low_readiness`
- `normal_readiness`
- `high_readiness`

Факторы:

- объём последних недель;
- количество пропусков;
- пауза;
- сон/стресс, если появятся;
- health notes;
- свежесть VDOT.

Эта readiness должна влиять на:

- стартовый объём;
- ceiling роста;
- частоту quality sessions;
- длину long run;
- агрессивность темпов.

## Policy по расписанию

Это должно быть полностью deterministic.

Правила:

- `preferred_days` трактуются как дни, выбранные пользователем для бега;
- если выбраны выходные, long ставится на последний выбранный выходной;
- если выходных среди беговых дней нет, long ставится на самый поздний выбранный беговой день недели;
- если `sessions_per_week == count(preferred_days)`, каждый выбранный день должен содержать беговую тренировку;
- race/control/taper placement не должен нарушать эту логику.

LLM не должна "решать", когда ставить отдых, если пользователь уже выбрал точные беговые дни.

## Prompt strategy v2

Нужен не один длинный narrative prompt, а чёткая структура:

1. `Athlete Facts`
2. `Derived Training State`
3. `Hard Constraints`
4. `Policy Decisions`
5. `Week Skeleton`
6. `Workout Detail Requirements`
7. `Output Schema`
8. `Self-Check`

### Что должно быть в Hard Constraints

- разрешённые беговые дни;
- целевой long day;
- limit по quality sessions;
- max weekly volume change;
- taper constraints;
- запрещённые комбинации;
- special population flags;
- VDOT source + confidence + pace policy.

### Что должно быть в Self-Check

- нет ли бега вне `preferred_days`;
- стоит ли long на правильном дне;
- нет ли 2 key workouts подряд;
- не нарушен ли taper;
- pace соответствует ли VDOT policy;
- нет ли weekly load spike сверх policy;
- есть ли хотя бы одна разгрузка в нужном месте цикла.

## Лучший продовый pipeline

### Этап 1. Skeleton planner

PHP строит:

- week phases;
- тип каждого дня;
- weekly distance/time targets;
- long run placement;
- quality day placement;
- recovery weeks;
- taper weeks.

### Этап 2. LLM detail filler

LLM получает skeleton и генерирует:

- структуру интервалов;
- duration/reps/rest;
- текст описания;
- notes по effort;
- OFP/SBU детали;
- race/fueling notes.

### Этап 3. Validator

Проверяет:

- schema;
- days;
- types;
- progression;
- pacing;
- safety;
- goal consistency.

### Этап 4. Repair

Если ошибка структурная:

- чинится детерминированно.

Если ошибка смысловая:

- one-shot corrective regeneration с коротким repair prompt.

## Что нужно изменить в коде

### 1. Вынести training state

Добавить:

- `planrun-backend/services/TrainingStateBuilder.php`

Он должен использовать текущие данные из:

- [../planrun-backend/controllers/StatsController.php](../planrun-backend/controllers/StatsController.php)
- [../planrun-backend/services/StatsService.php](../planrun-backend/services/StatsService.php)
- [../planrun-backend/controllers/WorkoutController.php](../planrun-backend/controllers/WorkoutController.php)

### 2. Вынести policy engine

Добавить:

- `GoalPolicyResolver`
- `SchedulePolicy`
- `VolumePolicy`
- `TaperPolicy`
- `VdotPolicy`
- `SafetyPolicy`

### 3. Упростить prompt_builder

`prompt_builder.php` должен перестать "думать" за бизнес-логику и начать только:

- сериализовать `training state`;
- сериализовать `policy decisions`;
- задавать схему ответа;
- задавать self-check.

### 4. Усилить normalizer/validator

Нужны отдельные проверки:

- `ScheduleValidator`
- `PaceValidator`
- `LoadValidator`
- `TaperValidator`
- `GoalConsistencyValidator`

### 5. Логирование и наблюдаемость

Для каждого плана хранить:

- `prompt_version`
- `policy_version`
- `vdot`
- `vdot_source`
- `vdot_confidence`
- `repair_count`
- `validation_errors`
- `generation_mode`

## Data model: что стоит добавить

- `users.vdot_current`
- `users.vdot_source`
- `users.vdot_confidence`
- `users.vdot_updated_at`
- `users.preferred_long_day` optional
- `users.special_population_flags` JSON
- `users.return_to_run_state` optional
- `users.last_control_date`
- `users.last_control_vdot`

Если не хотите сразу менять схему, сначала можно вычислять всё на лету в `TrainingStateBuilder`.

## Evaluation framework

Без regression-набора сервис не будет "безотказным".

Нужен набор минимум из `30-50` канонических кейсов:

- 3 дня в неделю без выходных;
- 4 дня с long в воскресенье;
- 6 дней, пятница off;
- 7 дней в неделю;
- новичок `couch_to_5k`;
- weight loss при низкой подготовке;
- first half;
- first marathon;
- advanced 5k improvement;
- stale VDOT;
- fresh control;
- low compliance;
- 10+ дней без тренировок;
- older adult `65+`;
- pregnant/postpartum;
- return after injury.

### Метрики качества

- `% планов без structural errors`
- `% планов без day-placement violations`
- `% планов без pace violations`
- `% планов без unsafe weekly spikes`
- `% планов без manual coach correction`
- `4-week adherence`
- `user acceptance of plan`
- `recalc success rate`

## Порядок внедрения

### Фаза 1. За 2-4 дня

- сделать `TrainingStateBuilder`;
- перевести prompt на единый VDOT/state;
- добавить `vdot_source`, `vdot_confidence`, `weeks_to_goal`, `readiness`;
- вынести Hard Constraints и Self-Check;
- расширить validator по темпам и weekly spikes.

### Фаза 2. За 1 неделю

- сделать deterministic skeleton planner;
- оставить LLM только workout-detail filler;
- унифицировать goal realism и plan generation на одном VDOT source;
- добавить prompt/version logging;
- собрать golden test set.

### Фаза 3. За 2-3 недели

- добавить policy-модули по special populations;
- добавить nutrition/fueling notes engine;
- добавить environment adjustments: heat, altitude, terrain;
- добавить coach review dashboard по validation failures.

### Фаза 4. Дальше

- отдельные mode-планы для `ultra`, trail, triathlon;
- динамический next-block planning по completed workouts;
- автоматические control workouts для обновления VDOT;
- bandit/AB test разных detail-prompts.

## Итоговая стратегия

Лучшая практика для вашего сервиса:

- `VDOT` и derived metrics считать централизованно;
- `schedule`, `volume`, `taper`, `safety` задавать детерминированно;
- `LLM` оставлять на вариативность и human-like detail generation;
- любой ответ модели прогонять через validator и repair;
- поддерживать не "100 промптов", а composable policy engine.

Это даст не просто "лучший prompt", а сервис, который:

- уважает выбор пользователя;
- лучше держит спортивную логику;
- не разваливается на edge cases;
- масштабируется на новые цели и сегменты.

## Источники

- CDC, Adult Activity: An Overview, 2023: https://www.cdc.gov/physical-activity-basics/guidelines/adults.html
- WHO, Guidelines on physical activity and sedentary behaviour, 2020/2021: https://www.who.int/publications/i/item/9789240014886
- CDC, Older Adults activity guidance, 2025: https://www.cdc.gov/physical-activity-basics/adding-older-adults/what-counts.html
- CDC, Pregnant & Postpartum Activity, 2025: https://www.cdc.gov/physical-activity-basics/guidelines/healthy-pregnant-or-postpartum-women.html
- CDC, Physical Activity and Weight, 2023: https://www.cdc.gov/healthy-weight-growth/physical-activity/index.html
- VDOT O2 Calculator: https://vdoto2.com/calculator/
- VDOT O2 Support, Adjusting Your Training Paces, 2022: https://support.vdoto2.com/2022/03/adjusting-your-training-paces-on-v-o2/
- VDOT O2, heat-adjusted pacing: https://news.vdoto2.com/2015/07/adjust-your-training-paces-for-high-temperatures/
- Meta-analysis, polarized vs other TID, 2024: https://pmc.ncbi.nlm.nih.gov/articles/PMC11329428/
- Systematic review, training load changes and injury, 2018: https://pmc.ncbi.nlm.nih.gov/articles/PMC6253751/
- Systematic review and meta-analysis, strength training and running economy, 2024: https://pmc.ncbi.nlm.nih.gov/articles/PMC11052887/
- Systematic review and meta-analysis, tapering in endurance athletes, 2023: https://pmc.ncbi.nlm.nih.gov/articles/PMC10171681/
- Recreational marathon taper analysis, 2021: https://pmc.ncbi.nlm.nih.gov/articles/PMC8506252/
- Detraining review, 2024: https://pmc.ncbi.nlm.nih.gov/articles/PMC10853933/
- Return-to-running scoping review, 2024: https://pmc.ncbi.nlm.nih.gov/articles/PMC11393297/
