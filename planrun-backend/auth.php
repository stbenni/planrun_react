<?php
/**
 * Многопользовательская система аутентификации
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_config.php';

/**
 * Проверка авторизации
 */
function isAuthenticated() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true && isset($_SESSION['user_id']);
}

// getCurrentUserId() и getCurrentUser() перенесены в user_functions.php для избежания дублирования

/**
 * Авторизация пользователя (проверка в БД)
 */
function login($username, $password) {
    $db = getDBConnection();
    $username = trim($username);
    $password = trim($password); // консистентность с регистрацией
    
    $stmt = $db->prepare('SELECT id, username, password FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['login_time'] = time();
        return true;
    }
    
    // Fallback для старой системы (обратная совместимость)
    if ($username === 'st_benni' && $password === 'aApzbz8h2ben') {
        $_SESSION['authenticated'] = true;
        $_SESSION['user_id'] = 1; // Дефолтный пользователь
        $_SESSION['username'] = $username;
        $_SESSION['login_time'] = time();
        return true;
    }
    
    return false;
}

/**
 * Выход из системы
 */
function logout() {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
}

/**
 * Требовать авторизацию (редирект на логин)
 */
function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}

