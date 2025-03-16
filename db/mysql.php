<?php
declare(strict_types=1);

/**
 * MySQL计数器数据库类
 *
 * 优化性能
 */
class MySQLDatabase implements DatabaseInterface{
    /** @var PDO|null 数据库连接实例 */
    private ?PDO $db = null;
    
    /** @var string 数据表名称 */
    private const TABLE_NAME = 'tb_count';
    
    /** @var int 字段最大长度限制 */
    private const MAX_NAME_LENGTH = 32;

    /**
     * 数据库配置
     */
    private const DB_CONFIG = [
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'counter_db',
        'username' => 'your_username',
        'password' => 'your_password',
        'charset' => 'utf8mb4'
    ];

    /**
     * PDO配置选项
     */
    private const PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        PDO::ATTR_PERSISTENT => true, // 使用持久连接
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ];

    /**
     * 构造函数：初始化数据库连接并应用性能优化配置
     * 
     * @throws PDOException 当数据库连接或初始化失败时抛出
     */
    public function __construct() {
        try {
            $this->connect();
            $this->applyPerformanceSettings();
            $this->createTable();
        } catch (PDOException $e) {
            error_log('MySQL Connection Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 建立数据库连接
     */
    private function connect(): void {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::DB_CONFIG['host'],
            self::DB_CONFIG['port'],
            self::DB_CONFIG['dbname'],
            self::DB_CONFIG['charset']
        );

        $this->db = new PDO(
            $dsn,
            self::DB_CONFIG['username'],
            self::DB_CONFIG['password'],
            self::PDO_OPTIONS
        );
    }

    /**
     * 应用MySQL性能优化设置
     */
    private function applyPerformanceSettings(): void {
        // 设置会话级别的性能参数
        $settings = [
            "innodb_flush_log_at_trx_commit = 2", // 降低磁盘I/O
            "innodb_flush_method = O_DIRECT",      // 直接I/O
            "transaction_isolation = 'READ-COMMITTED'", // 适合计数器场景的隔离级别
            "sql_mode = 'STRICT_TRANS_TABLES'"     // 严格模式
        ];

        foreach ($settings as $setting) {
            $this->db->exec("SET SESSION $setting");
        }
    }

    /**
     * 创建数据表和索引
     */
    private function createTable(): void {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(" . self::MAX_NAME_LENGTH . ") NOT NULL,
                num BIGINT NOT NULL DEFAULT 0,
                UNIQUE KEY idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * 获取指定计数器的值
     * 
     * @param string $name 计数器名称
     * @return array 包含name和num的关联数组，如果不存在返回默认值
     */
    public function getNum(string $name): array {
        $stmt = $this->db->prepare(
            'SELECT name, num FROM ' . self::TABLE_NAME . ' WHERE name = ?'
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch();

        return $row ?: ['name' => $name, 'num' => 0];
    }

    /**
     * 获取所有计数器的值
     * 
     * @return array 所有计数器数据的数组
     */
    public function getAll(): array {
        return $this->db->query('SELECT * FROM ' . self::TABLE_NAME)->fetchAll();
    }

    /**
     * 设置指定计数器的值
     * 
     * @param string $name 计数器名称
     * @param int $num 计数值
     */
    public function setNum(string $name, int $num): void {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE_NAME . ' (name, num) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE num = VALUES(num)'
        );
        
        $stmt->execute([$name, $num]);
    }

    /**
     * 批量设置多个计数器的值
     * 
     * @param array $counters 计数器数组，每个元素必须包含name和num键
     * @throws PDOException 当更新失败时抛出
     */
    public function setNumMulti(array $counters): void {
        $this->db->beginTransaction();
        
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ' . self::TABLE_NAME . ' (name, num) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE num = VALUES(num)'
            );

            foreach ($counters as $counter) {
                if (!isset($counter['name'], $counter['num'])) {
                    continue;
                }
                $stmt->execute([$counter['name'], (int)$counter['num']]);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw new PDOException(
                "批量更新失败: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * 增加计数器值（原子操作）
     * 
     * @param string $name 计数器名称
     * @param int $increment 增加值
     * @return int 更新后的值
     */
    public function increment(string $name, int $increment = 1): int {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE_NAME . ' (name, num) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE num = num + ?'
        );
        
        $stmt->execute([$name, $increment, $increment]);
        
        // 返回更新后的值
        return (int)$this->getNum($name)['num'];
    }

    /**
     * 析构函数：关闭数据库连接
     */
    public function __destruct() {
        $this->db = null; // PDO会自动关闭连接
    }
}
