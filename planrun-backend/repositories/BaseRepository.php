<?php
/**
 * Базовый класс для всех репозиториев
 * Содержит общую логику для работы с БД
 */

require_once __DIR__ . '/../config/Logger.php';

abstract class BaseRepository {
    protected $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Выполнить запрос и вернуть результат
     */
    protected function query($sql, $params = [], $types = '') {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new Exception('Ошибка подготовки запроса: ' . $this->db->error);
        }
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        
        if ($stmt->error) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Ошибка выполнения запроса: ' . $error);
        }
        
        return $stmt;
    }
    
    /**
     * Получить одну запись
     */
    protected function fetchOne($sql, $params = [], $types = '') {
        $stmt = $this->query($sql, $params, $types);
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
    
    /**
     * Получить все записи
     */
    protected function fetchAll($sql, $params = [], $types = '') {
        $stmt = $this->query($sql, $params, $types);
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
    
    /**
     * Выполнить INSERT/UPDATE/DELETE
     */
    protected function execute($sql, $params = [], $types = '') {
        $stmt = $this->query($sql, $params, $types);
        $affectedRows = $stmt->affected_rows;
        $insertId = $stmt->insert_id;
        $stmt->close();
        return [
            'affected_rows' => $affectedRows,
            'insert_id' => $insertId
        ];
    }
}
