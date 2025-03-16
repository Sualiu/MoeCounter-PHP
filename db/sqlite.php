<?php
declare(strict_types=1);

class SQLiteDatabase implements DatabaseInterface {
    /** @var SQLite3|null 数据库连接实例 */
    private $db;
    
    /** @var string 数据表名称 */
    private const TABLE_NAME = 'tb_count';
    
    /** @var string 数据库文件相对路径 */
    private const DB_PATH = '/count.db';
    
    /** @var int 字段最大长度限制 */
    private const MAX_NAME_LENGTH = 32;

    /**
     * 构造函数：初始化数据库连接并应用性能优化配置
     * 
     * @throws Exception 当数据库连接或初始化失败时抛出
     */
    public function __construct() {
        $dbPath = __DIR__ . self::DB_PATH;
        
        try {
            $this->db = new SQLite3($dbPath);
            
            // 应用性能优化配置
            $this->applyPerformanceSettings();
            
            // 创建数据表结构
            $this->createTable();
        } catch (Exception $e) {
            error_log('SQLite Connection Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 应用SQLite性能优化设置
     * 
     * WAL模式：提供更好的并发性能
     * synchronous = NORMAL：在性能和安全性之间取得平衡
     * cache_size：提供2MB的页面缓存
     * temp_store：将临时表和索引存储在内存中
     */
    private function applyPerformanceSettings(): void {
        $pragmas = [
            // 启用WAL模式，提供更好的并发性能
            'journal_mode' => 'WAL',
            // 在性能和安全性之间取得平衡
            'synchronous' => 'NORMAL',
            // 设置2MB的页面缓存
            'cache_size' => -2000,
            // 临时表和索引存储在内存中
            'temp_store' => 'MEMORY'
        ];

        foreach ($pragmas as $key => $value) {
            $this->db->exec("PRAGMA {$key} = {$value}");
        }
        
        // 设置忙等待超时时间为5秒
        $this->db->busyTimeout(5000);
    }

    /**
     * 创建数据表和索引
     * 
     * 表结构说明：
     * - id: 主键，使用SQLite的ROWID特性
     * - name: 计数器名称，唯一索引
     * - num: 计数值，默认为0
     */
    private function createTable(): void {
        // 创建主表
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE_NAME . ' (
            id    INTEGER      PRIMARY KEY
                              NOT NULL
                              UNIQUE,
            name  VARCHAR(' . self::MAX_NAME_LENGTH . ') NOT NULL
                              UNIQUE,
            num   BIGINT      NOT NULL
                              DEFAULT (0) 
        )');
        
        // 创建name字段的索引以优化查询性能
        $this->db->exec(
            "CREATE INDEX IF NOT EXISTS idx_name ON " . self::TABLE_NAME . "(name)"
        );
    }

    /**
     * 获取指定计数器的值
     * 
     * @param string $name 计数器名称
     * @return array 包含name和num的关联数组，如果不存在返回默认值
     */
    public function getNum($name): array {
        $stmt = $this->db->prepare('SELECT name, num FROM ' . self::TABLE_NAME . ' WHERE name = :name');
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ?: ['name' => $name, 'num' => 0];
    }

    /**
     * 获取所有计数器的值
     * 
     * @return array 所有计数器数据的数组
     */
    public function getAll(): array {
        $result = $this->db->query('SELECT * FROM ' . self::TABLE_NAME);
        $rows = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 设置指定计数器的值
     * 
     * @param string $name 计数器名称
     * @param int $num 计数值
     */
    public function setNum($name, $num): void {
        $stmt = $this->db->prepare('
            INSERT INTO ' . self::TABLE_NAME . ' (name, num) 
            VALUES (:name, :num) 
            ON CONFLICT(name) DO UPDATE SET num = :num
        ');
        
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':num', $num, SQLITE3_INTEGER);
        $stmt->execute();
    }

    /**
     * 批量设置多个计数器的值
     * 使用事务来确保原子性和提高性能
     * 
     * @param array $counters 计数器数组，每个元素必须包含name和num键
     * @throws Exception 当更新失败时抛出
     */
    public function setNumMulti($counters): void {
        // 使用IMMEDIATE事务来减少锁竞争
        $this->db->exec('BEGIN IMMEDIATE');
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO ' . self::TABLE_NAME . ' (name, num) 
                VALUES (:name, :num) 
                ON CONFLICT(name) DO UPDATE SET num = :num
            ');

            foreach ($counters as $counter) {
                $stmt->bindValue(':name', $counter['name'], SQLITE3_TEXT);
                $stmt->bindValue(':num', $counter['num'], SQLITE3_INTEGER);
                $stmt->execute();
                $stmt->reset(); // 重置语句，避免内存累积
            }

            $this->db->exec('COMMIT');
        } catch (Exception $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * 关闭数据库连接
     */
    public function close(): void {
        if ($this->db instanceof SQLite3) {
            $this->db->close();
        }
    }

    /**
     * 析构函数：确保数据库连接被正确关闭
     */
    public function __destruct() {
        $this->close();
    }
}
