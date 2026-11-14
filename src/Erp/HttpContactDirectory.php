<?php
declare(strict_types=1);

namespace ConnectTalk\Erp;

use ConnectTalk\Contracts\ContactDirectory;

/**
 * Identifica quem escreveu consultando o ERP (docs/api-erp.md, seção 2):
 *   GET /talk/people?phones=5531999998888,31999998888,...
 * O mesmo telefone pode estar em mais de um condomínio: o ERP devolve a lista e o Talk usa
 * o primeiro cadastro, marcando quantos existem (a Jack só age sozinha quando há um só).
 * Se o ERP estiver fora do ar, segue sem identificar (o atendimento não para).
 */
final class HttpContactDirectory implements ContactDirectory
{
    public function __construct(private ErpApi $api, private ?\Closure $logger = null)
    {
    }

    public function findByPhone(array $phoneCandidates): ?array
    {
        try {
            $r = $this->api->call('GET', '/talk/people?phones=' . rawurlencode(implode(',', $phoneCandidates)));
        } catch (\Throwable $e) {
            $this->logger && ($this->logger)('ERP indisponível na identificação: ' . $e->getMessage());
            return null;
        }
        $list = $r['data']['data'] ?? null;
        if ($r['status'] === 404 || !is_array($list) || $list === [] || empty($list[0]['id'])) {
            return null;
        }
        $p = $list[0];
        $details = array_filter([
            'vinculo' => $p['relationship'] ?? null,
            'condominio' => $p['condominium']['name'] ?? null,
            'unidade' => $p['unit']['label'] ?? null,
            'condominio_id' => $p['condominium']['id'] ?? null,
            'unidade_id' => $p['unit']['id'] ?? null,
            'email' => $p['email'] ?? null,
            'cadastros' => count($list) > 1 ? count($list) : null,
        ], fn ($v) => $v !== null && $v !== '');
        return ['person_id' => (int) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'details' => $details];
    }
}
