<?php
declare(strict_types=1);

namespace ConnectTalk\Erp;

use ConnectTalk\Http\HttpClient;

/**
 * Cliente da API REST que o Connect ERP expõe para o Talk.
 * Contrato completo em docs/api-erp.md.
 */
final class ErpApi
{
    public function __construct(private HttpClient $http, private string $baseUrl, private string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** @return array{status:int, data:mixed} */
    public function call(string $method, string $path, ?array $body = null): array
    {
        $r = $this->http->request($method, $this->baseUrl . $path, $body, [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Talk-Client' => 'connect-talk',
        ]);
        $data = $r['body'] !== '' ? json_decode($r['body'], true) : null;
        if ($r['status'] >= 500 || ($r['status'] >= 400 && $r['status'] !== 404)) {
            $msg = is_array($data) ? (string) ($data['error']['message'] ?? $data['error']['code'] ?? '') : '';
            throw new \RuntimeException("ERP respondeu {$r['status']} em $method $path" . ($msg ? ": $msg" : ''));
        }
        return ['status' => $r['status'], 'data' => $data];
    }
}
