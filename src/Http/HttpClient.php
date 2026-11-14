<?php
declare(strict_types=1);

namespace ConnectTalk\Http;

interface HttpClient
{
    /** @return array{status:int, body:string} */
    public function postJson(string $url, array $body, array $headers = []): array;

    /**
     * Requisição genérica (GET/POST/PATCH...). $body null = sem corpo.
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, ?array $body = null, array $headers = []): array;
}
