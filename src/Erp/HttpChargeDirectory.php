<?php
declare(strict_types=1);

namespace ConnectTalk\Erp;

use ConnectTalk\Contracts\ChargeDirectory;

/**
 * 2ª via pelo ERP: GET /talk/charges?resident_id=&phone= (docs/api-erp.md §6). 404 do ERP (morador de outro
 * vínculo, inativo ou telefone diferente do cadastro) vira lista vazia: o ERP não diz o motivo, e o Talk também não.
 */
final class HttpChargeDirectory implements ChargeDirectory
{
    public function __construct(private ErpApi $api)
    {
    }

    public function openCharges(int $personId, string $phone): ?array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $r = $this->api->call('GET', '/talk/charges?resident_id=' . $personId . '&phone=' . rawurlencode($digits));
        if ($r['status'] === 404) {
            return [];
        }
        $list = $r['data']['data'] ?? null;
        if (!is_array($list)) {
            throw new \RuntimeException('Resposta inesperada do ERP na 2ª via.');
        }
        $out = [];
        foreach (array_slice($list, 0, 3) as $c) {
            if (!is_array($c) || empty($c['id'])) {
                continue;
            }
            $out[] = [
                'id' => (int) $c['id'],
                'condominium' => (string) ($c['condominium'] ?? ''),
                'unit' => (string) ($c['unit'] ?? ''),
                'competence' => (string) ($c['competence'] ?? ''),
                'due_on' => (string) ($c['due_on'] ?? ''),
                'amount_cents' => (int) ($c['amount_cents'] ?? 0),
                'digitable_line' => isset($c['digitable_line']) && $c['digitable_line'] !== '' ? (string) $c['digitable_line'] : null,
                'pix_copy_paste' => isset($c['pix_copy_paste']) && $c['pix_copy_paste'] !== '' ? (string) $c['pix_copy_paste'] : null,
                'portal_url' => (string) ($c['portal_url'] ?? ''),
            ];
        }
        return $out;
    }
}
