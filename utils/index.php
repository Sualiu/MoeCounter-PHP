<?php
declare(strict_types=1);

/**
 * 从数组中随机返回一个元素
 * 
 * @template T
 * @param array<T> $arr
 * @return T
 */
function randomArray(array $arr): mixed {
    return $arr[array_rand($arr)];
}

/**
 * 将数字四舍五入到指定的小数位数
 */
function toFixed(float $num, int $digits = 2): float {
    return round($num, $digits);
}

/**
 * 日志级别枚举
 */
enum LogLevel: string {
    case DEBUG = 'DEBUG';
    case INFO = 'INFO';
    case WARN = 'WARN';
    case ERROR = 'ERROR';
    case FATAL = 'FATAL';
    
    /**
     * 获取级别优先级（数字越小，优先级越高）
     */
    public function getPriority(): int {
        return match($this) {
            self::DEBUG => 100,
            self::INFO => 200,
            self::WARN => 300,
            self::ERROR => 400,
            self::FATAL => 500,
        };
    }
    
    /**
     * 从PHP错误常量获取对应的日志级别
     */
    public static function fromPhpError(int $errorCode): self {
        define('E_STRICT', 2048);

        return match($errorCode) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => self::ERROR,
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => self::WARN,
            E_NOTICE, E_USER_NOTICE => self::INFO,
            E_DEPRECATED, E_USER_DEPRECATED => self::DEBUG,
            default => self::ERROR,
        };
    }
}

/**
 * 日志记录接口
 */
interface LoggerInterface {
    public function log(LogLevel $level, string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warn(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function fatal(string $message, array $context = []): void;
}

/**
 * 日志格式化器接口
 */
interface LogFormatterInterface {
    /**
     * 格式化日志记录
     *
     * @param LogLevel $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文数据
     * @param \DateTimeImmutable $timestamp 时间戳
     * @return string 格式化后的日志字符串
     */
    public function format(LogLevel $level, string $message, array $context, \DateTimeImmutable $timestamp): string;
}

/**
 * 日志处理器接口
 */
interface LogHandlerInterface {
    /**
     * 处理日志记录
     *
     * @param LogLevel $level 日志级别
     * @param string $message 日志消息
     * @param array $context 上下文数据
     * @param \DateTimeImmutable $timestamp 时间戳
     */
    public function handle(LogLevel $level, string $message, array $context, \DateTimeImmutable $timestamp): void;
    
    /**
     * 设置最低日志级别
     */
    public function setMinLevel(LogLevel $level): self;
    
    /**
     * 设置日志格式化器
     */
    public function setFormatter(LogFormatterInterface $formatter): self;
}

/**
 * 标准日志格式化器
 */
class StandardLogFormatter implements LogFormatterInterface {
    /**
     * @param string $dateFormat 日期格式
     */
    public function __construct(private string $dateFormat = 'Y-m-d H:i:s') {}
    
    public function format(LogLevel $level, string $message, array $context, \DateTimeImmutable $timestamp): string {
        // 处理上下文占位符替换
        $message = $this->interpolate($message, $context);
        
        // 构建基本日志记录
        $log = sprintf(
            "[%s] [%s] %s",
            $timestamp->format($this->dateFormat),
            $level->value,
            $message
        );
        
        // 添加上下文数据（如果有且不为空）
        if (!empty($context)) {
            $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $log .= " " . $contextStr;
        }
        
        return $log . PHP_EOL;
    }
    
    /**
     * 替换消息中的占位符
     */
    private function interpolate(string $message, array $context): string {
        $replace = [];
        foreach ($context as $key => $val) {
            // 忽略对象类型的上下文（除非可以转为字符串）
            if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }
        
        return strtr($message, $replace);
    }
}

/**
 * JSON日志格式化器
 */
class JsonLogFormatter implements LogFormatterInterface {
    public function format(LogLevel $level, string $message, array $context, \DateTimeImmutable $timestamp): string {
        $log = [
            'timestamp' => $timestamp->format('c'), // ISO 8601
            'level' => $level->value,
            'message' => $this->interpolate($message, $context),
            'context' => $context
        ];
        
        return json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    
    /**
     * 替换消息中的占位符
     */
    private function interpolate(string $message, array $context): string {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }
        
        return strtr($message, $replace);
    }
}

/**
 * 文件日志处理器
 */
class FileLogHandler implements LogHandlerInterface {
    private LogLevel $minLevel;
    private LogFormatterInterface $formatter;
    
    /**
     * @param string $logFile 日志文件路径
     * @param LogLevel $minLevel 最低日志级别
     * @param LogFormatterInterface|null $formatter 日志格式化器
     */
    public function __construct(
        private string $logFile, 
        LogLevel $minLevel = LogLevel::INFO,
        ?LogFormatterInterface $formatter = null
    ) {
        $this->minLevel = $minLevel;
        $this->formatter = $formatter ?? new StandardLogFormatter();
        
        // 确保日志目录存在
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    public function handle(LogLevel $level, string $message, array $context, \DateTimeImmutable $timestamp): void {
        // 检查日志级别
        if ($level->getPriority() < $this->minLevel->getPriority()) {
            return;
        }
        
        $formattedLog = $this->formatter->format($level, $message, $context, $timestamp);
        error_log($formattedLog, 3, $this->logFile);
    }
    
    public function setMinLevel(LogLevel $level): self {
        $this->minLevel = $level;
        return $this;
    }
    
    public function setFormatter(LogFormatterInterface $formatter): self {
        $this->formatter = $formatter;
        return $this;
    }
}

/**
 * 错误响应处理器
 */
class ErrorResponseHandler {
    /**
     * 发送JSON错误响应
     *
     * @param int $statusCode HTTP状态码
     * @param string $message 错误消息
     * @param array $details 错误详情
     */
    public function sendJsonResponse(int $statusCode = 500, string $message = 'Internal Server Error', array $details = []): never {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
        }
        
        $response = [
            'error' => [
                'code' => $statusCode,
                'message' => $message
            ]
        ];
        
        if (!empty($details) && getenv('APP_DEBUG') === 'true') {
            $response['error']['details'] = $details;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

/**
 * 主日志类
 */
class Logger implements LoggerInterface {
    /** @var LogHandlerInterface[] 日志处理器列表 */
    private array $handlers = [];
    
    /** @var ErrorResponseHandler|null 错误响应处理器 */
    private ?ErrorResponseHandler $errorResponseHandler = null;
    
    /** @var Logger|null 单例实例 */
    private static ?self $instance = null;
    
    /**
     * 获取单例实例
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 添加日志处理器
     */
    public function addHandler(LogHandlerInterface $handler): self {
        $this->handlers[] = $handler;
        return $this;
    }
    
    /**
     * 设置错误响应处理器
     */
    public function setErrorResponseHandler(ErrorResponseHandler $handler): self {
        $this->errorResponseHandler = $handler;
        return $this;
    }
    
    /**
     * 记录任意级别的日志
     */
    public function log(LogLevel $level, string $message, array $context = []): void {
        $timestamp = new \DateTimeImmutable();
        
        foreach ($this->handlers as $handler) {
            $handler->handle($level, $message, $context, $timestamp);
        }
    }
    
    /**
     * 记录调试级别日志
     */
    public function debug(string $message, array $context = []): void {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
    
    /**
     * 记录信息级别日志
     */
    public function info(string $message, array $context = []): void {
        $this->log(LogLevel::INFO, $message, $context);
    }
    
    /**
     * 记录警告级别日志
     */
    public function warn(string $message, array $context = []): void {
        $this->log(LogLevel::WARN, $message, $context);
    }
    
    /**
     * 记录错误级别日志
     */
    public function error(string $message, array $context = []): void {
        $this->log(LogLevel::ERROR, $message, $context);
    }
    
    /**
     * 记录致命错误级别日志
     */
    public function fatal(string $message, array $context = []): void {
        $this->log(LogLevel::FATAL, $message, $context);
    }
    
    /**
     * 配置错误处理
     */
    public function configureErrorHandling(bool $displayErrors = false): self {
        // 设置错误处理
        set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
            $level = LogLevel::fromPhpError($errno);
            $this->log($level, $errstr, [
                'file' => $errfile,
                'line' => $errline,
                'code' => $errno
            ]);
            
            // 致命错误时退出应用
            if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
                if ($this->errorResponseHandler) {
                    $this->errorResponseHandler->sendJsonResponse(500, 'Internal Server Error', [
                        'message' => $errstr,
                        'file' => $errfile,
                        'line' => $errline
                    ]);
                }
            }
            
            return true;
        });
        
        // 设置异常处理
        set_exception_handler(function(\Throwable $exception) {
            $this->log(LogLevel::ERROR, $exception->getMessage(), [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]);
            
            if ($this->errorResponseHandler) {
                $this->errorResponseHandler->sendJsonResponse(500, 'Internal Server Error', [
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine()
                ]);
            }
        });
        
        // 设置致命错误处理
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $this->log(LogLevel::FATAL, $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'code' => $error['type']
                ]);
                
                if ($this->errorResponseHandler && !headers_sent()) {
                    $this->errorResponseHandler->sendJsonResponse(500, 'Fatal Error', [
                        'message' => $error['message'],
                        'file' => $error['file'],
                        'line' => $error['line']
                    ]);
                }
            }
        });
        
        // 配置错误显示
        ini_set('display_errors', $displayErrors ? '1' : '0');
        ini_set('display_startup_errors', $displayErrors ? '1' : '0');
        
        return $this;
    }
}

/**
 * 日志工厂类
 */
class LoggerFactory {
    /**
     * 创建一个标准的生产环境日志配置
     */
    public static function createProductionLogger(string $logDir = 'logs'): Logger {
        $logger = Logger::getInstance();
        
        // 创建日志文件处理器
        $fileHandler = new FileLogHandler(
            $logDir . '/app.log',
            LogLevel::INFO,
            new StandardLogFormatter()
        );
        
        // 创建错误日志文件处理器
        $errorHandler = new FileLogHandler(
            $logDir . '/error.log',
            LogLevel::ERROR,
            new JsonLogFormatter()
        );
        
        // 添加处理器
        $logger->addHandler($fileHandler)
               ->addHandler($errorHandler)
               ->setErrorResponseHandler(new ErrorResponseHandler())
               ->configureErrorHandling(false);
        
        return $logger;
    }
    
    /**
     * 创建一个用于开发环境的日志配置
     */
    public static function createDevelopmentLogger(string $logDir = 'logs'): Logger {
        $logger = Logger::getInstance();
        
        // 创建日志文件处理器
        $fileHandler = new FileLogHandler(
            $logDir . '/app.log',
            LogLevel::DEBUG,
            new StandardLogFormatter()
        );
        
        // 添加处理器
        $logger->addHandler($fileHandler)
               ->setErrorResponseHandler(new ErrorResponseHandler())
               ->configureErrorHandling(false);
        
        return $logger;
    }
}

// 使用示例
// $logger = LoggerFactory::createDevelopmentLogger();
// $logger->info("用户已登录", ['user_id' => 123, 'ip' => '192.168.1.1']);
// 简单使用
/*
$logger = LoggerFactory::createProductionLogger();
$logger->info("系统启动完成");
$logger->error("数据库连接失败", ['host' => 'db.example.com', 'error' => 'Connection refused']);

// 自定义配置
$logger = new Logger();
$logger->addHandler(new FileLogHandler('app.log'))
       ->addHandler(new ConsoleLogHandler(LogLevel::DEBUG))
       ->configureErrorHandling();

// 在应用中使用
try {
    // 业务代码
} catch (\Exception $e) {
    $logger->error("操作失败", [
        'exception' => $e,
        'user_id' => $userId
    ]);
}
*/
