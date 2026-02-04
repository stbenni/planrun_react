<?php
/**
 * Константы проекта PlanRun
 * Централизованное хранение всех магических значений
 */

/**
 * Типы целей тренировок
 */
class GoalTypes {
    const HEALTH = 'health';
    const RACE = 'race';
    const WEIGHT_LOSS = 'weight_loss';
    const TIME_IMPROVEMENT = 'time_improvement';
    
    /**
     * Получить все типы целей
     */
    public static function getAll() {
        return [
            self::HEALTH,
            self::RACE,
            self::WEIGHT_LOSS,
            self::TIME_IMPROVEMENT
        ];
    }
    
    /**
     * Проверить валидность типа цели
     */
    public static function isValid($goalType) {
        return in_array($goalType, self::getAll(), true);
    }
}

/**
 * Уровни опыта бегуна
 */
class ExperienceLevels {
    const BEGINNER = 'beginner';
    const INTERMEDIATE = 'intermediate';
    const ADVANCED = 'advanced';
    
    /**
     * Получить все уровни
     */
    public static function getAll() {
        return [
            self::BEGINNER,
            self::INTERMEDIATE,
            self::ADVANCED
        ];
    }
    
    /**
     * Проверить валидность уровня
     */
    public static function isValid($level) {
        return in_array($level, self::getAll(), true);
    }
}

/**
 * Полы пользователей
 */
class Genders {
    const MALE = 'male';
    const FEMALE = 'female';
    
    /**
     * Получить все полы
     */
    public static function getAll() {
        return [
            self::MALE,
            self::FEMALE
        ];
    }
    
    /**
     * Проверить валидность пола
     */
    public static function isValid($gender) {
        return in_array($gender, self::getAll(), true);
    }
}

/**
 * Типы тренировок
 */
class WorkoutTypes {
    const REST = 'rest';
    const EASY = 'easy';
    const LONG = 'long';
    const TEMPO = 'tempo';
    const INTERVAL = 'interval';
    const OTHER = 'other';
    const SBU = 'sbu';
    const FREE = 'free';
    const RACE = 'race';
    
    /**
     * Получить все типы тренировок
     */
    public static function getAll() {
        return [
            self::REST,
            self::EASY,
            self::LONG,
            self::TEMPO,
            self::INTERVAL,
            self::OTHER,
            self::SBU,
            self::FREE,
            self::RACE
        ];
    }
    
    /**
     * Проверить валидность типа тренировки
     */
    public static function isValid($type) {
        return in_array($type, self::getAll(), true);
    }
}

/**
 * Категории упражнений
 */
class ExerciseCategories {
    const RUN = 'run';
    const OFP = 'ofp';
    const SBU = 'sbu';
    
    /**
     * Получить все категории
     */
    public static function getAll() {
        return [
            self::RUN,
            self::OFP,
            self::SBU
        ];
    }
    
    /**
     * Проверить валидность категории
     */
    public static function isValid($category) {
        return in_array($category, self::getAll(), true);
    }
}

/**
 * Роли пользователей
 */
class UserRoles {
    const ADMIN = 'admin';
    const COACH = 'coach';
    const USER = 'user';
    
    /**
     * Получить все роли
     */
    public static function getAll() {
        return [
            self::ADMIN,
            self::COACH,
            self::USER
        ];
    }
    
    /**
     * Проверить валидность роли
     */
    public static function isValid($role) {
        return in_array($role, self::getAll(), true);
    }
}

/**
 * Программы для здоровья
 */
class HealthPrograms {
    const START_RUNNING = 'start_running';
    const COUCH_TO_5K = 'couch_to_5k';
    const REGULAR_RUNNING = 'regular_running';
    const CUSTOM = 'custom';
    
    /**
     * Получить все программы
     */
    public static function getAll() {
        return [
            self::START_RUNNING,
            self::COUCH_TO_5K,
            self::REGULAR_RUNNING,
            self::CUSTOM
        ];
    }
    
    /**
     * Проверить валидность программы
     */
    public static function isValid($program) {
        return in_array($program, self::getAll(), true);
    }
}

/**
 * Уровни бега для программ здоровья
 */
class RunningLevels {
    const ZERO = 'zero';
    const BASIC = 'basic';
    const COMFORTABLE = 'comfortable';
    
    /**
     * Получить все уровни
     */
    public static function getAll() {
        return [
            self::ZERO,
            self::BASIC,
            self::COMFORTABLE
        ];
    }
    
    /**
     * Проверить валидность уровня
     */
    public static function isValid($level) {
        return in_array($level, self::getAll(), true);
    }
}


