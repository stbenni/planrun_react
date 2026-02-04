<?php
/**
 * Конфигурация кеширования для масштабирования
 * 
 * Поддерживает:
 * - Redis (рекомендуется)
 * - Memcached (альтернатива)
 * - Файловый кеш (fallback, если нет Redis/Memcached)
 * 
 * ВАЖНО: Конфигурация загружается из .env файла
 */

// Загружаем переменные окружения
require_once __DIR__ . '/config/env_loader.php';

// Тип кеша: 'redis', 'memcached', 'file', 'none'
define('CACHE_TYPE', env('CACHE_TYPE', 'file')); // По умолчанию файловый

// Настройки Redis
define('REDIS_HOST', env('REDIS_HOST', 'localhost'));
define('REDIS_PORT', (int)env('REDIS_PORT', 6379));
define('REDIS_PASSWORD', env('REDIS_PASSWORD', null));
define('REDIS_DATABASE', (int)env('REDIS_DATABASE', 0));

// Настройки Memcached
define('MEMCACHED_HOST', env('MEMCACHED_HOST', 'localhost'));
define('MEMCACHED_PORT', (int)env('MEMCACHED_PORT', 11211));

// Настройки файлового кеша
define('CACHE_DIR', __DIR__ . '/cache');
define('CACHE_DEFAULT_TTL', (int)env('CACHE_DEFAULT_TTL', 3600)); // 1 час по умолчанию

/**
 * Получить экземпляр кеша
 */
function getCache() {
    static $cache = null;
    
    if ($cache === null) {
        switch (CACHE_TYPE) {
            case 'redis':
                $cache = new RedisCache();
                break;
            case 'memcached':
                $cache = new MemcachedCache();
                break;
            case 'file':
                $cache = new FileCache();
                break;
            default:
                $cache = new NullCache(); // Нет кеша
        }
    }
    
    return $cache;
}

/**
 * Базовый класс кеша
 */
abstract class CacheInterface {
    abstract public function get($key);
    abstract public function set($key, $value, $ttl = null);
    abstract public function delete($key);
    abstract public function clear();
}

/**
 * Redis кеш
 */
class RedisCache extends CacheInterface {
    private $redis;
    
    public function __construct() {
        if (!extension_loaded('redis')) {
            throw new Exception('Redis extension not loaded');
        }
        
        $this->redis = new Redis();
        $this->redis->connect(REDIS_HOST, REDIS_PORT);
        
        if (REDIS_PASSWORD) {
            $this->redis->auth(REDIS_PASSWORD);
        }
        
        $this->redis->select(REDIS_DATABASE);
    }
    
    public function get($key) {
        $value = $this->redis->get($key);
        return $value !== false ? unserialize($value) : null;
    }
    
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl ?? CACHE_DEFAULT_TTL;
        return $this->redis->setex($key, $ttl, serialize($value));
    }
    
    public function delete($key) {
        return $this->redis->del($key);
    }
    
    public function clear() {
        return $this->redis->flushDB();
    }
}

/**
 * Memcached кеш
 */
class MemcachedCache extends CacheInterface {
    private $memcached;
    
    public function __construct() {
        if (!extension_loaded('memcached')) {
            throw new Exception('Memcached extension not loaded');
        }
        
        $this->memcached = new Memcached();
        $this->memcached->addServer(MEMCACHED_HOST, MEMCACHED_PORT);
    }
    
    public function get($key) {
        $value = $this->memcached->get($key);
        return $value !== false ? $value : null;
    }
    
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl ?? CACHE_DEFAULT_TTL;
        return $this->memcached->set($key, $value, $ttl);
    }
    
    public function delete($key) {
        return $this->memcached->delete($key);
    }
    
    public function clear() {
        return $this->memcached->flush();
    }
}

/**
 * Файловый кеш
 */
class FileCache extends CacheInterface {
    private $dir;
    
    public function __construct() {
        $this->dir = CACHE_DIR;
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
    }
    
    private function getFilePath($key) {
        $hash = md5($key);
        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }
    
    public function get($key) {
        $file = $this->getFilePath($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $data = unserialize(file_get_contents($file));
        
        // Проверяем TTL
        if ($data['expires'] < time()) {
            @unlink($file);
            return null;
        }
        
        return $data['value'];
    }
    
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl ?? CACHE_DEFAULT_TTL;
        $file = $this->getFilePath($key);
        $dir = dirname($file);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        
        return file_put_contents($file, serialize($data)) !== false;
    }
    
    public function delete($key) {
        $file = $this->getFilePath($key);
        return @unlink($file);
    }
    
    public function clear() {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'cache') {
                @unlink($file->getRealPath());
            }
        }
        
        return true;
    }
}

/**
 * Пустой кеш (для отладки)
 */
class NullCache extends CacheInterface {
    public function get($key) { return null; }
    public function set($key, $value, $ttl = null) { return true; }
    public function delete($key) { return true; }
    public function clear() { return true; }
}

/**
 * Удобные функции для работы с кешем
 */
class Cache {
    /**
     * Получить значение из кеша или вычислить и сохранить
     * 
     * @param string $key Ключ кеша
     * @param callable $callback Функция для вычисления значения
     * @param int $ttl Время жизни в секундах
     * @return mixed Значение из кеша или результат callback
     */
    public static function remember($key, $callback, $ttl = 3600) {
        $cache = getCache();
        $value = $cache->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $cache->set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * Получить значение из кеша
     */
    public static function get($key) {
        return getCache()->get($key);
    }
    
    /**
     * Сохранить значение в кеш
     */
    public static function set($key, $value, $ttl = 3600) {
        return getCache()->set($key, $value, $ttl);
    }
    
    /**
     * Удалить значение из кеша
     */
    public static function delete($key) {
        return getCache()->delete($key);
    }
    
    /**
     * Инвалидация кеша по паттерну
     */
    public static function invalidate($pattern) {
        $cache = getCache();
        
        // Для файлового кеша
        if ($cache instanceof FileCache) {
            $files = glob(__DIR__ . '/cache/**/*.cache');
            foreach ($files as $file) {
                $content = unserialize(file_get_contents($file));
                if (isset($content['key']) && fnmatch($pattern, $content['key'])) {
                    @unlink($file);
                }
            }
        }
        
        // Для других типов кеша просто удаляем по ключу
        return $cache->delete($pattern);
    }
    
    /**
     * Очистить весь кеш
     */
    public static function clear() {
        return getCache()->clear();
    }
}



