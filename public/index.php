<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    [, , , $app] = Tesco\Bootstrap::services();
    $body = file_get_contents('php://input', false, null, 0, 65537);
    if ($body === false || strlen($body) > 65536) {
        throw new Tesco\ApiError('request_too_large', 'Request body exceeds 64 KiB.', 413);
    }
    [$status, $result] = $app->handle($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', $_GET, $body, $_SERVER['HTTP_AUTHORIZATION'] ?? '', $_SERVER['CONTENT_TYPE'] ?? '');
} catch (Tesco\ApiError $e) {
    $status = $e->status;
    $result = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
} catch (Throwable $e) {
    error_log(get_class($e) . ': ' . $e->getMessage());
    $status = 500;
    $result = ['error' => ['code' => 'internal_error', 'message' => 'The service could not complete the request. Check server logs.']];
}
http_response_code($status);
if ($status === 401) {
    header('WWW-Authenticate: Bearer');
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
