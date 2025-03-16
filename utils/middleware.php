<?php
declare(strict_types=1);

function parseError($error) {
    return [
        'code' => 400,
        'message' => "Invalid input: $error"
    ];
}

function validateInput($input, $rules) {
    // Implement validation logic similar to Zod
    // This is a simplified version and you'd want a more robust validation
    foreach ($rules as $field => $rule) {
        if (!isset($input[$field]) || !$rule($input[$field])) {
            return parseError("Invalid $field");
        }
    }
    return null;
}

function corsMiddleware($options = []) {
    $allowOrigins = $options['allowOrigins'] ?? '*';
    $allowMethods = $options['allowMethods'] ?? 'GET, POST, PUT, DELETE';

    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_HOST'];

    $isOriginAllowed = function($origin) use ($allowOrigins) {
        if (is_array($allowOrigins)) {
            return in_array($origin, $allowOrigins);
        }
        return $allowOrigins === '*' || $allowOrigins === $origin;
    };

    if ($origin && $isOriginAllowed($origin)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");      
    }else {
        die('请求与期望不一致');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $requestMethod = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? null;
        if ($requestMethod) {
            header("Access-Control-Allow-Methods: $requestMethod");
        } else {
            header("Access-Control-Allow-Methods: $allowMethods");
        }

        $requestHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? null;
        if ($requestHeaders) {
            header("Access-Control-Allow-Headers: $requestHeaders");
        }

        http_response_code(204);
        exit();
    }
}
