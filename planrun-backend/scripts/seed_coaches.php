#!/usr/bin/env php
<?php
/**
 * Создание 10 тестовых тренеров с заполненными описаниями
 * Запуск: php scripts/seed_coaches.php
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

$coaches = [
    [
        'username' => 'Алексей Морозов',
        'slug' => 'aleksey_morozov',
        'email' => 'coach1@planrun.local',
        'bio' => 'Марафонский тренер с 12-летним опытом. Помог более 200 атлетам финишировать марафон. Специализируюсь на подготовке к первому марафону и улучшении личных рекордов. Индивидуальный подход к каждому ученику.',
        'specialization' => ['marathon', 'half_marathon', 'beginner'],
        'philosophy' => 'Регулярность важнее интенсивности. Слушай тело и прогрессируй постепенно.',
        'experience' => 12,
        'accepts' => 1,
        'prices_on_request' => 0,
        'pricing' => [
            ['type' => 'individual', 'label' => 'Индивидуальный план', 'price' => 8000, 'currency' => 'RUB', 'period' => 'month'],
            ['type' => 'consultation', 'label' => 'Разовая консультация', 'price' => 3000, 'currency' => 'RUB', 'period' => 'one_time'],
        ],
    ],
    [
        'username' => 'Мария Белова',
        'slug' => 'maria_belova',
        'email' => 'coach2@planrun.local',
        'bio' => 'Трейлраннер и ультрамарафонец. Победитель и призёр российских трейловых забегов. Готовлю к горным марафонам, ультра и трейлам. Работаю с атлетами любого уровня — от новичков до опытных.',
        'specialization' => ['ultra', 'trail', 'marathon'],
        'philosophy' => 'Бег в горах — это диалог с природой и собой. Научимся читать рельеф и распределять силы.',
        'experience' => 8,
        'accepts' => 1,
        'prices_on_request' => 0,
        'pricing' => [
            ['type' => 'individual', 'label' => 'Подготовка к ультра', 'price' => 10000, 'currency' => 'RUB', 'period' => 'month'],
            ['type' => 'group', 'label' => 'Групповые тренировки', 'price' => 5000, 'currency' => 'RUB', 'period' => 'month'],
        ],
    ],
    [
        'username' => 'Дмитрий Козлов',
        'slug' => 'dmitry_kozlov',
        'email' => 'coach3@planrun.local',
        'bio' => 'Специалист по дистанциям 5–10 км и полумарафону. Бывший легкоатлет, мастер спорта. Фокус на технике бега, интервальных тренировках и работе над скоростью. Помогаю выйти на новый уровень темпа.',
        'specialization' => ['5k_10k', 'half_marathon'],
        'philosophy' => 'Скорость — это навык. Правильная техника и системные тренировки творят чудеса.',
        'experience' => 15,
        'accepts' => 1,
        'prices_on_request' => 0,
        'pricing' => [
            ['type' => 'individual', 'label' => 'Персональный план', 'price' => 7000, 'currency' => 'RUB', 'period' => 'month'],
        ],
    ],
    [
        'username' => 'Елена Соколова',
        'slug' => 'elena_sokolova',
        'email' => 'coach4@planrun.local',
        'bio' => 'Тренер для начинающих бегунов. Помогаю safely перейти от ходьбы к бегу, избежать травм и полюбить движение. Работаю с людьми после долгого перерыва в спорте и с нуля.',
        'specialization' => ['beginner', 'injury_recovery'],
        'philosophy' => 'Каждый может бегать. Важно начать правильно и не торопиться с нагрузками.',
        'experience' => 6,
        'accepts' => 1,
        'prices_on_request' => 0,
        'pricing' => [
            ['type' => 'individual', 'label' => 'Старт с нуля', 'price' => 5000, 'currency' => 'RUB', 'period' => 'month'],
        ],
    ],
    [
        'username' => 'Андрей Волков',
        'slug' => 'andrey_volkov',
        'email' => 'coach5@planrun.local',
        'bio' => 'Эксперт по восстановлению после травм и возвращению в бег. Физиотерапевт и беговой тренер. Специализируюсь на работе с атлетами после переломов, растяжений и операций.',
        'specialization' => ['injury_recovery', 'beginner'],
        'philosophy' => 'Травма — не приговор. Грамотная реабилитация и постепенная нагрузка вернут вас на дистанцию.',
        'experience' => 10,
        'accepts' => 1,
        'prices_on_request' => 1,
        'pricing' => [],
    ],
    [
        'username' => 'Ольга Новикова',
        'slug' => 'olga_novikova',
        'email' => 'coach6@planrun.local',
        'bio' => 'Марафонский тренер и нутрициолог. Комплексный подход: план тренировок + питание для выносливости. Помогаю подготовиться к марафону с учётом восстановления и энергетического баланса.',
        'specialization' => ['marathon', 'nutrition'],
        'philosophy' => 'Тренировки и питание — два крыла одного самолёта. Работаем над обоими.',
        'experience' => 9,
        'accepts' => 1,
        'prices_on_request' => 0,
        'pricing' => [
            ['type' => 'individual', 'label' => 'Тренировки + питание', 'price' => 12000, 'currency' => 'RUB', 'period' => 'month'],
        ],
    ],
    [
        'username' => 'Сергей Петров',
        'slug' => 'sergey_petrov',
        'email' => 'coach7@planrun.local',
        'bio' => 'Тренер по марафону и полумарафону. Личный рекорд 2:28 на марафоне. Работаю с атлетами, нацеленными на результат. Структурированные планы, анализ тренировок, работа над слабыми местами.',
        'specialization' => ['marathon', 'half_marathon'],
        'philosophy' => 'Дисциплина и терпение. Марафон прощают ошибки в подготовке только тем, кто готовился правильно.',
        'experience' => 11,
        'accepts' => 1,
        'prices_on_request' => 0,
        'pricing' => [
            ['type' => 'individual', 'label' => 'Подготовка к марафону', 'price' => 9000, 'currency' => 'RUB', 'period' => 'month'],
        ],
    ],
    [
        'username' => 'Наталья Кузнецова',
        'slug' => 'natalya_kuznetsova',
        'email' => 'coach8@planrun.local',
        'bio' => 'Тренер по ментальной подготовке и работе с целями. Помогаю преодолеть страх дистанции, прокрастинацию и выгорание. Бег как инструмент для ясности ума и устойчивости.',
        'specialization' => ['mental', 'beginner'],
        'philosophy' => 'Бег — это медитация в движении. Учимся слышать себя и управлять состоянием.',
        'experience' => 7,
        'accepts' => 1,
        'prices_on_request' => 1,
        'pricing' => [],
    ],
    [
        'username' => 'Игорь Смирнов',
        'slug' => 'igor_smirnov',
        'email' => 'coach9@planrun.local',
        'bio' => 'Универсальный тренер: от 5 км до марафона. Работаю с любителями и продвинутыми атлетами. Акцент на разнообразие тренировок, СБУ, ОФП и профилактику травм.',
        'specialization' => ['5k_10k', 'half_marathon', 'marathon'],
        'philosophy' => 'Сильный корпус — быстрые ноги. ОФП и СБУ не менее важны, чем километраж.',
        'experience' => 14,
        'accepts' => 1,
        'prices_on_request' => 0,
        'pricing' => [
            ['type' => 'individual', 'label' => 'Комплексный план', 'price' => 7500, 'currency' => 'RUB', 'period' => 'month'],
            ['type' => 'consultation', 'label' => 'Анализ тренировок', 'price' => 2500, 'currency' => 'RUB', 'period' => 'one_time'],
        ],
    ],
    [
        'username' => 'Виктория Орлова',
        'slug' => 'victoria_orlova',
        'email' => 'coach10@planrun.local',
        'bio' => 'Тренер для женщин 40+. Специализация: комфортный бег, подготовка к первому полумарафону и марафону с учётом гормонов, восстановления и образа жизни. Без жёстких нагрузок — с умом.',
        'specialization' => ['beginner', 'half_marathon', 'marathon'],
        'philosophy' => 'Возраст — не ограничение. Адаптируем нагрузку под ваш ритм жизни и цели.',
        'experience' => 8,
        'accepts' => 1,
        'prices_on_request' => 0,
        'pricing' => [
            ['type' => 'individual', 'label' => 'План для 40+', 'price' => 6000, 'currency' => 'RUB', 'period' => 'month'],
        ],
    ],
];

$passwordHash = password_hash('coach123', PASSWORD_DEFAULT);
$created = 0;
$skipped = 0;

foreach ($coaches as $c) {
    $check = $db->prepare("SELECT id FROM users WHERE username_slug = ? OR email = ?");
    $check->bind_param("ss", $c['slug'], $c['email']);
    $check->execute();
    if ($check->get_result()->fetch_assoc()) {
        $skipped++;
        $check->close();
        continue;
    }
    $check->close();

    $specializationJson = json_encode($c['specialization'], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("
        INSERT INTO users (
            username, username_slug, password, email, role,
            onboarding_completed, training_mode, goal_type, gender,
            coach_bio, coach_specialization, coach_accepts, coach_prices_on_request,
            coach_experience_years, coach_philosophy, last_activity
        ) VALUES (?, ?, ?, ?, 'coach', 1, 'coach', 'health', 'male', ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param(
        "ssssssiiis",
        $c['username'],
        $c['slug'],
        $passwordHash,
        $c['email'],
        $c['bio'],
        $specializationJson,
        $c['accepts'],
        $c['prices_on_request'],
        $c['experience'],
        $c['philosophy']
    );

    if (!$stmt->execute()) {
        fwrite(STDERR, "Ошибка при создании {$c['username']}: " . $stmt->error . "\n");
        $stmt->close();
        continue;
    }
    $coachId = $stmt->insert_id;
    $stmt->close();

    foreach ($c['pricing'] as $i => $p) {
        $type = $p['type'] ?? 'custom';
        $label = $p['label'] ?? 'Услуга';
        $price = $p['price'] ?? null;
        $currency = $p['currency'] ?? 'RUB';
        $period = $p['period'] ?? 'month';
        $pStmt = $db->prepare("INSERT INTO coach_pricing (coach_id, type, label, price, currency, period, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $pStmt->bind_param("issdssi", $coachId, $type, $label, $price, $currency, $period, $i);
        $pStmt->execute();
        $pStmt->close();
    }

    $created++;
    echo "OK: {$c['username']} (id=$coachId)\n";
}

echo "\nСоздано тренеров: $created, пропущено (уже есть): $skipped\n";
