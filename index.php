<?php
// 启用严格模式
declare(strict_types=1);

// 解析配置文件
function getConfig() {
    $env = parse_ini_file('.env', false, INI_SCANNER_TYPED) ?: [];
    
    return $env;
}

// 结构化配置
$config = [
    'app' => [
        'site' => getConfig()['APP_SITE'] ?? '',
        'ga_id' => getConfig()['GA_ID'] ?? '',
    ],
    'cors' => [
        'allowOrigins' => ['http://example.com', parse_url(getConfig()['APP_SITE'] ?? '', PHP_URL_HOST) ?? ''],
        'allowMethods' => 'GET',
    ],
    'cache' => [
        'enabled' => true,
        'ttl' => 3600,
        'flushInterval' => 10, // 每10秒刷新缓存到数据库
    ],
];

// Require utility files
require_once 'utils/themify.php';
require_once 'utils/middleware.php';
require_once 'utils/index.php';
require_once 'db/index.php';

// 服务容器
$services = [];

// 服务获取函数
function getService(string $name) {
    global $services;
    static $instances = [];
    
    if (!isset($instances[$name])) {
        if (!isset($services[$name])) {
            throw new RuntimeException("Service not found: $name");
        }
        $instances[$name] = $services[$name]();
    }
    
    return $instances[$name];
}

// 注册服务
$services = [
    'logger' => fn() => LoggerFactory::createDevelopmentLogger(__DIR__),
    'db' => fn() => DatabaseConnection::getInstance(getConfig()),
    'themify' => fn() => Themify::getInstance(),
];

// 获取服务实例
$logger = getService('logger');
$db = getService('db');
$themify = getService('themify');

// 初始化全局缓存计数器
$cacheCounter = [];
$lastPushTime = time();
/* 
// 尝试从APCu获取
$success = false;
$cacheKey = 'cacheCounter';
$cacheCounter = apcu_fetch($cacheKey, $success);
if (!$success) {

}                
// 更新缓存
apcu_store($cacheKey, $cacheCounter, 3600);
*/

// 路由处理
$parsedSite = parse_url($config['app']['site']) ?: [];
$base_uri = '/' . trim($parsedSite['path'] ?? '', '/'); // 基础路径
$base_host = $parsedSite['host'] ?? '';

// 规范化请求URI
$request_uri = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');

// 路由匹配逻辑优化
$request_route = null;

// 检查是否包含index.php并提前处理
if (stripos($request_uri, 'index.php') !== false) {
    // 处理无伪静态的情况，移除index.php部分
    $request_route = '/' . preg_replace('/^(.+\/)?index\.php\//', '', $request_uri);
} else {
    // 检查请求是否包含基础路径，并提取实际路由
    if ($base_uri === '/' || strpos($request_uri, $base_uri) === 0) {
        $request_route = $base_uri === '/' ? 
            $request_uri : 
            '/' . ltrim(substr($request_uri, strlen($base_uri)), '/');
    } else {
        $logger->error("路由不匹配: 基础路径 {$base_uri}, 请求路径 {$request_uri}");
        http_response_code(400);
        exit('配置的站点路径与实际请求路径不一致');
    }
}

// 日志记录当前路由信息
$logger->debug("路由匹配: 基础路径={$base_uri}, 请求路径={$request_uri}, 提取路由={$request_route}");

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 调用CORS中间件函数
corsMiddleware($config['cors']);

// 应用中间件
$middlewareResult = applyMiddleware(function() use ($request_route, $method, $logger, $db, $config, $themify, &$cacheCounter) {
    // 路由处理
    $routeParts = explode('/', trim($request_route, '/'));
    
    // 定义路由处理器
    $staticRoutes = [
        '/' => fn() => renderIndex($config['app']['site'], $config['app']['ga_id']),
        '/heart-beat' => fn() => handleHeartBeat($logger),
    ];
    
    // 处理静态路由
    if (isset($staticRoutes[$request_route])) {
        return $staticRoutes[$request_route]();
    }
    
    // 处理动态路由
    if (!empty($routeParts)) {
        // 处理计数器路由 /@name
        if (str_starts_with($routeParts[0], '@')) {
            $name = substr($routeParts[0], 1);
            return handleCounterRoute($logger, $db, $themify, $name, $cacheCounter);
        }
        
        // 处理记录路由 /record/@name
        if ($routeParts[0] === 'record' && isset($routeParts[1]) && str_starts_with($routeParts[1], '@')) {
            $name = substr($routeParts[1], 1);
            return handleRecordRoute($logger, $db, $name, $cacheCounter);
        }
    }
    
    // 404处理
    http_response_code(404);
    return "Cannot $method $request_route";
}, [
    // 日志中间件
    function($next) use ($logger, $request_route, $method) {
        $start = microtime(true);
        $result = $next();
        $duration = microtime(true) - $start;
        $logger->info("$method $request_route processed in {$duration}s");
        return $result;
    },
    // 错误处理中间件
    function($next) use ($logger) {
        try {
            return $next();
        } catch (Exception $e) {
            $logger->error("Error: " . $e->getMessage());
            http_response_code(500);
            return "Internal Server Error";
        }
    }
]);

echo $middlewareResult;

// 应用中间件辅助函数
function applyMiddleware(callable $handler, array $middlewares): mixed {
    $next = $handler;
    foreach (array_reverse($middlewares) as $middleware) {
        $next = function() use ($middleware, $next) {
            return $middleware($next);
        };
    }
    return $next();
}

// 展示首页
function renderIndex(string $site, string|int $ga_id): void {
    $static_site = false;
    if(getConfig()['REWRITE_ENABLED'] == false) {
        $static_site = $site;    
        $site = $site . '/index.php';
    }

    $themify = getService('themify');
    $themeList = $themify->loadThemes();
    include 'views/index.php';
}

// 统一的参数过滤函数
function filterParam(int $type, string $name, array $options): mixed {
    $filter = match ($options['type'] ?? 'int') {
        'int' => FILTER_VALIDATE_INT,
        'float' => FILTER_VALIDATE_FLOAT,
        'string' => FILTER_UNSAFE_RAW,
        default => FILTER_UNSAFE_RAW,
    };

    $filterOptions = [];
    if (isset($options['options'])) {
        $filterOptions['options'] = $options['options'];
    }
    
    $value = filter_input($type, $name, $filter, $filterOptions);

    // 处理枚举类型
    if (isset($options['allowed_values']) && !in_array($value, $options['allowed_values'], true)) {
        $value = $options['default'];
    }

    return $value !== false && $value !== null ? $value : $options['default'];
}

// 处理计数器路由
function handleCounterRoute(Logger $logger,$db, Themify $themify, string $name, array &$cacheCounter): void {
    // 验证计数器名称
    if (!is_string($name) || strlen($name) > 32 || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
        $logger->warn("Invalid counter name attempted: {$name}");
        http_response_code(400);
        exit('Invalid counter name');
    }

    $req = [
        'theme' => filterParam(INPUT_GET, 'theme', [
            'type' => 'string',
            'default' => 'moebooru',
        ]),
        'padding' => filterParam(INPUT_GET, 'padding', [
            'type' => 'int',
            'default' => 7,
            'options' => ['min_range' => 0, 'max_range' => 16]
        ]),
        'offset' => filterParam(INPUT_GET, 'offset', [
            'type' => 'float',
            'default' => 0,
            'options' => ['min_range' => -500, 'max_range' => 500]
        ]),
        'align' => filterParam(INPUT_GET, 'align', [
            'type' => 'string',
            'default' => 'top',
            'allowed_values' => ['top', 'center', 'bottom']
        ]),
        'scale' => filterParam(INPUT_GET, 'scale', [
            'type' => 'float',
            'default' => 1,
            'options' => ['min_range' => 0.1, 'max_range' => 2]
        ]),
        'pixelated' => filterParam(INPUT_GET, 'pixelated', [
            'type' => 'int',
            'default' => 1,
            'allowed_values' => [0, 1]
        ]),
        'darkmode' => filterParam(INPUT_GET, 'darkmode', [
            'type' => 'string',
            'default' => 'auto',
            'allowed_values' => ['0', '1', 'auto']
        ]),
        'num' => filterParam(INPUT_GET, 'num', [
            'type' => 'int',
            'default' => 0,
            'options' => ['min_range' => 0, 'max_range' => 1e15]
        ]),
        'prefix' => filterParam(INPUT_GET, 'prefix', [
            'type' => 'int',
            'default' => -1,
            'options' => ['min_range' => -1, 'max_range' => 999999]
        ])
    ];

    try {
        $data = getCountByName($db, $logger, $name, $cacheCounter, $req['num']);
    } catch (\Exception $e) {
        $logger->error("Database error: " . $e->getMessage());
        http_response_code(500);
        exit("Database error");
    }

    // 设置响应头
    header('Content-Type: image/svg+xml');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    
    // 随机主题处理
    if ($req['theme'] === 'random') {
        $themeList = array_keys($themify->loadThemes());
        $req['theme'] = $themeList[array_rand($themeList)];
    }

    // 绘制 SVG 图像
    $req['count'] = $data['num'] ?? 0;
    echo $themify->getCountImage($req);

    // 日志记录
    $logger->info(json_encode([
        'data' => $data,
        'request' => [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'referrer' => $_SERVER['HTTP_REFERER'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ],
        'params' => $req
    ]));
}

function handleRecordRoute(Logger $logger,$db, string $name, array &$cacheCounter): void {
    // 验证计数器名称
    if (!is_string($name) || strlen($name) > 32 || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
        $logger->warn("Invalid counter name attempted: {$name}");
        http_response_code(400);
        exit('Invalid counter name');
    }
    
    $req = ['num' => 0];
    try {
        $data = getCountByName($db, $logger, $name, $cacheCounter, $req['num']);
    } catch (\Exception $e) {
        $logger->error("Database error: " . $e->getMessage());
        http_response_code(500);
        exit("Database error");
    }
    
    header('Content-Type: application/json');
    echo json_encode($data);
}

function handleHeartBeat(Logger $logger): void {
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    echo "alive";
    $logger->info("heart-beat");
}

function pushDB($db, Logger $logger, array &$cacheCounter, bool $forceFlush = false): void {
    global $lastPushTime;
    $currentTime = time();
    
    // 如果缓存为空或者时间间隔不够且不是强制刷新，则不推送
    if (empty($cacheCounter) || ($currentTime - $lastPushTime < 10 && !$forceFlush)) {
        //return;
    }
    
    try {
        $counters = array_map(static function($key, $value) {
            return [
                'name' => $key,
                'num' => $value
            ];
        }, array_keys($cacheCounter), $cacheCounter);

        // 批量更新数据库
        $db->setNumMulti($counters);
        
        // 更新最后推送时间
        $lastPushTime = $currentTime;
        
        // 清空缓存计数器
        if ($forceFlush) {
            $cacheCounter = [];
        }
        
        $logger->info("pushDB: " . json_encode($counters));
    } catch (Exception $error) {
        $logger->error("pushDB error: " . $error->getMessage());
    }
}

function getCountByName($db, Logger $logger, string $name, array &$cacheCounter, int $num = 0): array {
    // 缓存暂不实现，留空。
    $defaultCount = ['name' => $name, 'num' => 0];

    // 特殊处理
    if ($name === 'demo') return ['name' => $name, 'num' => '0123456789'];
    if ($num > 0) return ['name' => $name, 'num' => $num];

    try {
        // 从缓存或数据库获取计数
        if (!isset($cacheCounter[$name])) {
            $counter = $db->getNum($name) ?? $defaultCount;
            $cacheCounter[$name] = (int)($counter['num'] ?? 0) + 1;
        } else {
            $cacheCounter[$name]++;
        }

        // 推送数据到数据库（非强制刷新）
        pushDB($db, $logger, $cacheCounter, false);

        return ['name' => $name, 'num' => $cacheCounter[$name]];
    } catch (Exception $error) {
        $logger->error("get count by name error: " . $error->getMessage());
        return $defaultCount;
    }
}
