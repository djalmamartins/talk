<?php
declare(strict_types=1);

namespace ConnectTalk\Http;

interface HttpClient
{
    /** @return array{status:int, body:string} */
    public function postJson(string $url, array $body, array $headers = []): array;
}
