<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Contracts\ChargeDirectory;
use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Support\Db;

/**
 * Botão "2ª via do boleto" na conversa (ADR 0005). O atendente vê os boletos em aberto do morador identificado e manda
 * um deles ao cliente com a linha digitável e o Pix copia e cola. Quem decide o que pode sair é o ERP (proprietário ou
 * responsável financeiro, telefone igual ao do cadastro, até 3 boletos). Ao enviar, o Talk busca de novo no ERP: o
 * navegador só escolhe qual boleto, nunca manda o texto do boleto.
 */
final class SecondCopy
{
    public function __construct(private Db $db, private AccessPolicy $policy, private ConversationService $conversations,
        private ChargeDirectory $charges)
    {
    }

    /** @return array{available:bool, reason:?string, charges:list<array<string,mixed>>} */
    public function list(StaffMember $u, int $convId): array
    {
        [$conv, $contact] = $this->load($u, $convId, false);
        if ($contact['erp_person_id'] === null) {
            return ['available' => false, 'reason' => 'Contato não identificado no cadastro do ERP. Confira o telefone no cadastro do morador.', 'charges' => []];
        }
        $list = $this->fetch($contact);
        if ($list === null) {
            return ['available' => false, 'reason' => 'A ligação com o ERP não está configurada (ERP_API_URL).', 'charges' => []];
        }
        return ['available' => true, 'reason' => $list === [] ? 'Nenhum boleto em aberto para este morador. Só sai boleto do proprietário ou do responsável financeiro com o telefone do cadastro.' : null,
            'charges' => array_map(fn (array $c): array => $c + ['amount' => self::money($c['amount_cents']), 'due' => self::date($c['due_on']),
                'reference' => self::competence($c['competence'])], $list)];
    }

    /** Manda o boleto escolhido ao cliente, como resposta do atendente. Devolve o id da mensagem. */
    public function send(StaffMember $u, int $convId, int $chargeId, ?string $signature = null): int
    {
        [, $contact] = $this->load($u, $convId, true);
        if ($contact['erp_person_id'] === null) {
            throw new DomainError('Contato não identificado no cadastro do ERP.');
        }
        $list = $this->fetch($contact) ?? [];
        foreach ($list as $c) {
            if ($c['id'] === $chargeId) {
                return $this->conversations->reply($convId, $u, self::message($c), $signature);
            }
        }
        throw new DomainError('Esse boleto não está mais em aberto. Atualize a lista.', 404);
    }

    /** Texto da mensagem ao cliente (regra pura). */
    public static function message(array $c): string
    {
        $lines = ['Segue a 2ª via do boleto' . ($c['condominium'] !== '' ? ' do ' . $c['condominium'] : '')
            . ($c['unit'] !== '' ? ', unidade ' . $c['unit'] : '') . ':', '',
            'Referência: ' . self::competence($c['competence']),
            'Vencimento: ' . self::date($c['due_on']),
            'Valor: ' . self::money($c['amount_cents'])];
        if ($c['digitable_line'] !== null) {
            array_push($lines, '', '*Linha digitável:*', $c['digitable_line']);
        }
        if ($c['pix_copy_paste'] !== null) {
            array_push($lines, '', '*Pix copia e cola:*', $c['pix_copy_paste']);
        }
        if ($c['portal_url'] !== '') {
            array_push($lines, '', 'Também está no aplicativo do morador: ' . $c['portal_url']);
        }
        return implode("\n", $lines);
    }

    public static function money(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    public static function date(string $ymd): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) ? "$m[3]/$m[2]/$m[1]" : $ymd;
    }

    public static function competence(string $ym): string
    {
        return preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) ? "$m[2]/$m[1]" : $ym;
    }

    /** Boletos do ERP; ERP fora do ar vira um aviso claro em vez de "erro interno". */
    private function fetch(array $contact): ?array
    {
        try {
            return $this->charges->openCharges((int) $contact['erp_person_id'], (string) $contact['phone']);
        } catch (\Throwable $e) {
            error_log('[talk] 2ª via: ' . $e->getMessage());
            throw new DomainError('O ERP não respondeu agora. Tente de novo em instantes.', 503);
        }
    }

    /** @return array{0:array, 1:array} conversa e contato, conferindo o acesso */
    private function load(StaffMember $u, int $convId, bool $act): array
    {
        $conv = $this->db->one('SELECT * FROM talk_conversations WHERE id = ?', [$convId]);
        if (!$conv || !($act ? $this->policy->canAct($u, $conv) : $this->policy->canView($u, $conv))) {
            throw new DomainError('Conversa não encontrada.', 404);
        }
        $contact = $this->db->one('SELECT id, phone, erp_person_id FROM talk_contacts WHERE id = ?', [(int) $conv['contact_id']]);
        return [$conv, $contact];
    }
}
