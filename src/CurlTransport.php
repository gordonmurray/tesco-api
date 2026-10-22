<?php
declare(strict_types=1);
namespace Tesco;

final class CurlTransport implements Transport
{
    private float $lastRequestAt = 0;

    public function __construct(private readonly string $apiKey) {}

    public function send(array $operation): array
    {
        // Pace sequential CLI/list requests. Separate HTTP workers still need
        // a reverse-proxy rate limit if the service is exposed remotely.
        $delay = 0.35 - (microtime(true) - $this->lastRequestAt);
        if ($delay > 0) {
            usleep((int) ($delay * 1000000));
        }
        $this->lastRequestAt = microtime(true);
        $body = '';
        $tooLarge = false;
        $handle = curl_init('https://xapi.tesco.com/');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([$operation], JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', 'Accept: application/json',
                'x-apikey: ' . $this->apiKey,
                'Origin: https://www.tesco.ie', 'Referer: https://www.tesco.ie/shop/',
                'language: en-IE', 'region: IE',
            ],
        ]);
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $errno = curl_errno($handle);
        if ($tooLarge) {
            throw new ApiError('upstream_response_too_large', 'Tesco returned a response larger than 4 MiB.');
        }
        if ($success === false) {
            throw new ApiError($errno === CURLE_OPERATION_TIMEDOUT ? 'upstream_timeout' : 'upstream_unavailable',
                'Could not reach Tesco. Try again later.', $errno === CURLE_OPERATION_TIMEDOUT ? 504 : 502);
        }
        return ['status' => $status, 'body' => $body];
    }
}
