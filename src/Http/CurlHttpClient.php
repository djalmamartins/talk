<?php
declare(strict_types=1);

namespace ConnectTalk\Http;

final class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeoutSeconds = 15)
    {
    }

    public function postJson(string $url, array $body, array $headers = []): array
    {
        $h = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP falhou: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $resp];
    }
}
