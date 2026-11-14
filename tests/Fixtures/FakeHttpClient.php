<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Fixtures;

use ConnectTalk\Http\HttpClient;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url:string, body:array, headers:array}> */
    public array $calls = [];
    /** @var list<array{status:int, body:string}|\Throwable> */
    public array $responses = [];
    private int $seq = 0;

    public function postJson(string $url, array $body, array $headers = []): array
    {
        $this->calls[] = compact('url', 'body', 'headers');
        $r = array_shift($this->responses);
        if ($r instanceof \Throwable) {
            throw $r;
        }
        return $r ?? ['status' => 201, 'body' => json_encode(['key' => ['id' => 'OUT' . (++$this->seq)]])];
    }
}
