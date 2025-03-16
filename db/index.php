<?php
declare(strict_types=1);
/*
function selectDatabase($env) {
    $dbType = $env ?? 'sqlite';

    switch ($dbType) {
        case 'mongodb':
            require_once __DIR__ . '/mongodb.php';
            return new MongoDatabase();
        case 'mysql':
            require_once __DIR__ . '/mysql.php';
            return new MySQLDatabase();
        case 'sqlite':
        default:
            require_once __DIR__ . '/sqlite.php';
            return new SQLiteDatabase();
    }
}
*/

/**
 * 基础数据库接口
 */
interface DatabaseInterface {
    public function getNum($name): array;
    public function getAll(): array;    
    public function setNum($name, $num): void;
    public function setNumMulti($counters): void;
}

/**
 * 数据库连接单例类
 */
class DatabaseConnection {
    private static $instances = [];
    
    /**
     * 获取数据库实例
     *
     * @param string $env 数据库类型配置
     * @return DatabaseInterface
     */
    public static function getInstance(array $env): DatabaseInterface {
        $type = $env['DB_TYPE'] ?? 'sqlite'; // 如果 $env['DB_TYPE'] 为空，则默认使用 'sqlite'
        
        if (!isset(self::$instances[$type])) {
            // 按需加载文件并创建实例
            switch ($type) {
                case 'mongodb':
                    require_once __DIR__ . '/mongodb.php';
                    self::$instances[$type] = new MongoDatabase();
                    break;
                case 'mysql':
                    require_once __DIR__ . '/mysql.php';
                    self::$instances[$type] = new MySQLDatabase();
                    break;
                case 'sqlite':
                    require_once __DIR__ . '/sqlite.php';
                    self::$instances[$type] = new SQLiteDatabase();
                    break;
                default:
                    throw new InvalidArgumentException("Unsupported database type: $type");
            }
        }
        
        return self::$instances[$type];
    }
}
