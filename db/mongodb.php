<?php
declare(strict_types=1);

/**
 * MongoDB计数器数据库类
 * 
 * 利用了MongoDB的原子操作和高性能特性
 */
class MongoDatabase implements DatabaseInterface {
    /** @var MongoDB\Client MongoDB客户端实例 */
    private MongoDB\Client $client;
    
    /** @var MongoDB\Collection 集合实例 */
    private MongoDB\Collection $collection;
    
    /** @var string 集合名称 */
    private const COLLECTION_NAME = 'counters';
    
    /** @var string 数据库名称 */
    private const DB_NAME = 'counter_db';
    
    /** @var int 字段最大长度限制 */
    private const MAX_NAME_LENGTH = 32;

    /**
     * MongoDB连接配置
     */
    private const MONGO_CONFIG = [
        'uri' => 'mongodb://localhost:27017',
        'options' => [
            'retryWrites' => true,
            'w' => 1,                    // 写入确认级别
            'readPreference' => 'primary', // 从主节点读取
            'maxStalenessSeconds' => 90,   // 最大陈旧时间
            'connectTimeoutMS' => 5000,    // 连接超时
            'serverSelectionTimeoutMS' => 5000, // 服务器选择超时
        ]
    ];

    /**
     * 构造函数：初始化MongoDB连接
     * 
     * @throws MongoDB\Driver\Exception\Exception 当连接失败时抛出
     */
    public function __construct() {
        try {
            $this->initializeConnection();
            $this->createIndexes();
        } catch (Exception $e) {
            error_log('MongoDB Connection Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 初始化MongoDB连接
     */
    private function initializeConnection(): void {
        $this->client = new MongoDB\Client(
            self::MONGO_CONFIG['uri'],
            self::MONGO_CONFIG['options']
        );

        $this->collection = $this->client
            ->selectDatabase(self::DB_NAME)
            ->selectCollection(self::COLLECTION_NAME);
    }

    /**
     * 创建索引
     */
    private function createIndexes(): void {
        $this->collection->createIndex(
            ['name' => 1],
            [
                'unique' => true,
                'background' => true,
                'name' => 'idx_name'
            ]
        );
    }

    /**
     * 获取指定计数器的值
     * 
     * @param string $name 计数器名称
     * @return array 包含name和num的关联数组
     */
    public function getNum(string $name): array {
        $name = $this->sanitizeName($name);
        
        $result = $this->collection->findOne(
            ['name' => $name],
            [
                'projection' => [
                    'name' => 1,
                    'num' => 1,
                    '_id' => 0
                ]
            ]
        );

        return $result ? [
            'name' => $result->name,
            'num' => (int)$result->num
        ] : ['name' => $name, 'num' => 0];
    }

    /**
     * 获取所有计数器的值
     * 
     * @return array 所有计数器数据的数组
     */
    public function getAll(): array {
        $cursor = $this->collection->find(
            [],
            [
                'projection' => [
                    'name' => 1,
                    'num' => 1,
                    '_id' => 0
                ]
            ]
        );

        $results = [];
        foreach ($cursor as $document) {
            $results[] = [
                'name' => $document->name,
                'num' => (int)$document->num
            ];
        }

        return $results;
    }

    /**
     * 设置指定计数器的值
     * 
     * @param string $name 计数器名称
     * @param int $num 计数值
     */
    public function setNum(string $name, int $num): void {
        $name = $this->sanitizeName($name);
        
        $this->collection->updateOne(
            ['name' => $name],
            [
                '$set' => ['num' => $num],
                '$setOnInsert' => ['created_at' => new MongoDB\BSON\UTCDateTime()]
            ],
            ['upsert' => true]
        );
    }

    /**
     * 批量设置多个计数器的值
     * 
     * @param array $counters 计数器数组，每个元素必须包含name和num键
     * @throws Exception 当更新失败时抛出
     */
    public function setNumMulti(array $counters): void {
        $operations = [];
        $now = new MongoDB\BSON\UTCDateTime();

        foreach ($counters as $counter) {
            if (!isset($counter['name'], $counter['num'])) {
                continue;
            }

            $name = $this->sanitizeName($counter['name']);
            $operations[] = [
                'updateOne' => [
                    ['name' => $name],
                    [
                        '$set' => ['num' => (int)$counter['num']],
                        '$setOnInsert' => ['created_at' => $now]
                    ],
                    ['upsert' => true]
                ]
            ];
        }

        if (!empty($operations)) {
            $this->collection->bulkWrite($operations);
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
        $name = $this->sanitizeName($name);
        
        $result = $this->collection->findOneAndUpdate(
            ['name' => $name],
            [
                '$inc' => ['num' => $increment],
                '$setOnInsert' => ['created_at' => new MongoDB\BSON\UTCDateTime()]
            ],
            [
                'upsert' => true,
                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'projection' => ['num' => 1]
            ]
        );

        return (int)$result->num;
    }

    /**
     * 批量递增计数器
     * 
     * @param array $increments 格式：['counter_name' => increment_value]
     * @return array 更新后的值数组
     */
    public function incrementMulti(array $increments): array {
        $operations = [];
        $now = new MongoDB\BSON\UTCDateTime();

        foreach ($increments as $name => $increment) {
            $name = $this->sanitizeName($name);
            $operations[] = [
                'updateOne' => [
                    ['name' => $name],
                    [
                        '$inc' => ['num' => (int)$increment],
                        '$setOnInsert' => ['created_at' => $now]
                    ],
                    ['upsert' => true]
                ]
            ];
        }

        if (!empty($operations)) {
            $this->collection->bulkWrite($operations);
        }

        // 获取更新后的值
        $names = array_keys($increments);
        $cursor = $this->collection->find(
            ['name' => ['$in' => $names]],
            ['projection' => ['name' => 1, 'num' => 1, '_id' => 0]]
        );

        $results = [];
        foreach ($cursor as $doc) {
            $results[$doc->name] = (int)$doc->num;
        }

        return $results;
    }

    /**
     * 清理计数器名称
     * 
     * @param string $name 计数器名称
     * @return string 清理后的名称
     */
    private function sanitizeName(string $name): string {
        return substr(trim($name), 0, self::MAX_NAME_LENGTH);
    }

    /**
     * 获取数据库统计信息
     * 
     * @return array 统计信息
     */
    public function getStats(): array {
        return $this->collection->count() ? [
            'total_counters' => $this->collection->countDocuments(),
            'storage_size' => $this->collection->stats()->size,
            'index_size' => $this->collection->stats()->totalIndexSize
        ] : ['total_counters' => 0, 'storage_size' => 0, 'index_size' => 0];
    }
}

/*
try {
    $db = new MongoDatabase();
    
    // 简单计数
    $db->setNum('page_visits', 100);
    $count = $db->getNum('page_visits');
    echo "访问次数：{$count['num']}\n";
    
    // 原子递增（适合高并发）
    $newCount = $db->increment('page_visits');
    echo "新的访问次数：{$newCount}\n";
    
    // 批量递增
    $results = $db->incrementMulti([
        'page1' => 1,
        'page2' => 5,
        'page3' => 10
    ]);
    print_r($results);
    
    // 批量设置
    $counters = [
        ['name' => 'page1', 'num' => 100],
        ['name' => 'page2', 'num' => 200]
    ];
    $db->setNumMulti($counters);
    
    // 获取统计信息
    $stats = $db->getStats();
    print_r($stats);
    
} catch (Exception $e) {
    error_log("MongoDB错误：" . $e->getMessage());
}
*/