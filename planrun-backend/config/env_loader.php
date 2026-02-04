<?php
/**
 * Загрузчик переменных окружения из .env файла
 * 
 * Безопасная загрузка конфигурации без внешних зависимостей
 */

/**
 * Загрузить переменные окружения из .env файла
 * 
 * @param string $envPath Путь к .env файлу
 * @return void
 */
function loadEnv($envPath = null) {
    if ($envPath === null) {
        $envPath = __DIR__ . '/../.env';
    }
    
    if (!file_exists($envPath)) {
        // Если .env не существует, используем значения по умолчанию
        // Это позволяет проекту работать без .env (для обратной совместимости)
        return;
    }
    
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Пропускаем комментарии
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Парсим KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Убираем кавычки если есть
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            
            // Устанавливаем переменную окружения, если еще не установлена
            if (!isset($_ENV[$key]) && !getenv($key)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

/**
 * Получить значение переменной окружения с fallback
 * 
 * @param string $key Ключ переменной
 * @param mixed $default Значение по умолчанию
 * @return mixed
 */
function env($key, $default = null) {
    // Сначала проверяем $_ENV
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    
    // Затем getenv()
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    
    // Возвращаем значение по умолчанию
    return $default;
}

// Автоматическая загрузка при подключении файла
loadEnv();
