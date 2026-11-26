<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Erp\ErpApi;
use ConnectTalk\Support\Db;

/**
 * Login único com o usuário do ERP (ADR 0006 do Talk, ADR 0643 do ERP).
 *
 * O botão "Entrar pelo Connect ERP" leva a ERP_WEB_URL/ambiente/talk. O ERP confere a senha e o 2FA e a permissão
 * talk.access (dada pelo papel ou liberada no cadastro do usuário) e devolve o navegador a /ambiente/entrar/talk/{código}.
 * Aqui o código vira o funcionário por POST /talk/staff/login (escopo staff.login do token).
 *
 * - O perfil do Talk é achado pelo erp_user_id; se não tiver, pelo e-mail (o supervisor criado no bin/setup.php);
 *   se não existir, nasce sem setor, e o supervisor escolhe o papel e os setores em Equipe e setores.
 * - Perfil desativado no Talk não entra, mesmo com acesso no ERP.
 * - revalidate(): de tempos em tempos pergunta ao ERP se o acesso continua; ERP fora do ar não derruba ninguém.
 */
final class ErpLogin
{
    public function __construct(private Db $db, private ?ErpApi $erp, private bool $on, private int $everySeconds = 600)
    {
    }

    /** Login só pelo ERP? (TALK_LOGIN=erp, o padrão quando há ERP_API_URL e ERP_WEB_URL) */
    public function enabled(): bool
    {
        return $this->on && $this->erp !== null;
    }

    /**
     * Troca o código pelo usuário do Talk.
     * @return array{id:int, name:string}|null null = código inválido/vencido ou sem acesso
     * @throws \RuntimeException ERP fora do ar ou token sem o escopo staff.login
     */
    public function exchange(string $code, ?string $ip = null): ?array
    {
        if (!$this->enabled() || !preg_match('/^[a-f0-9]{64}$/', $code)) {
            return null;
        }
        $r = $this->erp->call('POST', '/talk/staff/login', ['code' => $code]);
        $who = is_array($r['data']['data'] ?? null) ? $r['data']['data'] : null;
        if ($r['status'] !== 200 || $who === null || (int) ($who['id'] ?? 0) <= 0) {
            return null;
        }
        $erpId = (int) $who['id'];
        $email = mb_strtolower(trim((string) ($who['email'] ?? '')));
        $name = mb_substr(trim((string) ($who['name'] ?? '')) ?: $email, 0, 100);

        return $this->db->transaction(function () use ($erpId, $email, $name, $ip): ?array {
            $u = $this->db->one('SELECT * FROM talk_users WHERE erp_user_id = ? AND is_bot = 0 LIMIT 1', [$erpId]);
            if (!$u && $email !== '') {
                // só liga pelo e-mail um perfil que ainda não é de outra pessoa do ERP
                $u = $this->db->one('SELECT * FROM talk_users WHERE email = ? AND erp_user_id IS NULL AND is_bot = 0', [$email]);
                if ($u) {
                    $this->db->run('UPDATE talk_users SET erp_user_id = ? WHERE id = ?', [$erpId, (int) $u['id']]);
                    $this->audit((int) $u['id'], 'erp_link', ['erp_user_id' => $erpId], $ip);
                }
            }
            if (!$u) {
                if ($email === '' || $this->db->one('SELECT id FROM talk_users WHERE email = ?', [$email])) {
                    return null; // e-mail já usado por outro perfil ligado a outra conta do ERP: o supervisor resolve
                }
                // senha aleatória que ninguém conhece: com o login pelo ERP ela não é usada
                $id = $this->db->insert('talk_users', [
                    'name' => $name, 'email' => $email, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                    'erp_user_id' => $erpId, 'active' => 1,
                ]);
                $this->audit($id, 'erp_first_login', ['erp_user_id' => $erpId], $ip);
                return ['id' => $id, 'name' => $name];
            }
            if ((int) $u['active'] !== 1) {
                return null;
            }
            if ($name !== '' && $name !== $u['name']) {
                $this->db->run('UPDATE talk_users SET name = ? WHERE id = ?', [$name, (int) $u['id']]);
            }
            return ['id' => (int) $u['id'], 'name' => $name ?: (string) $u['name']];
        });
    }

    /**
     * A sessão continua valendo? Pergunta ao ERP no máximo a cada $everySeconds (guarda a hora em $lastCheck).
     * false só quando o ERP responde que o acesso acabou; erro de rede mantém a sessão.
     */
    public function revalidate(int $userId, int &$lastCheck, ?int $now = null): bool
    {
        $now ??= time();
        if (!$this->enabled() || $now - $lastCheck < $this->everySeconds) {
            return true;
        }
        $u = $this->db->one('SELECT erp_user_id FROM talk_users WHERE id = ?', [$userId]);
        $erpId = (int) ($u['erp_user_id'] ?? 0);
        if ($erpId <= 0) {
            return false; // com o login pelo ERP, perfil sem conta do ERP não fica dentro
        }
        try {
            $r = $this->erp->call('GET', '/talk/staff/' . $erpId);
        } catch (\Throwable $e) {
            error_log('[talk] revalidar login no ERP: ' . $e->getMessage());
            return true;
        }
        $lastCheck = $now;
        return $r['status'] === 200 && ($r['data']['data']['active'] ?? false) === true;
    }

    /**
     * Equipe vinda do ERP (1.5.0): GET /talk/staff traz quem tem talk.access. Quem é novo entra sem setor (o supervisor
     * escolhe papel e setores aqui); nome e e-mail seguem o ERP; quem perdeu o acesso no ERP é desativado no Talk.
     * Não reativa quem o supervisor desativou.
     *
     * @return array{added:int, updated:int, deactivated:int, total:int}
     * @throws \RuntimeException ERP fora do ar ou token sem staff.login
     */
    public function syncTeam(?int $actorId = null, ?string $ip = null): array
    {
        if (!$this->enabled()) {
            return ['added' => 0, 'updated' => 0, 'deactivated' => 0, 'total' => 0];
        }
        $r = $this->erp->call('GET', '/talk/staff');
        $list = $r['status'] === 200 && is_array($r['data']['data'] ?? null) ? $r['data']['data'] : null;
        if ($list === null) {
            throw new \RuntimeException('O ERP não devolveu a lista da equipe.');
        }
        return $this->db->transaction(function () use ($list, $actorId, $ip): array {
            $out = ['added' => 0, 'updated' => 0, 'deactivated' => 0, 'total' => count($list)];
            $seen = [];
            foreach ($list as $p) {
                $erpId = (int) ($p['id'] ?? 0);
                $email = mb_strtolower(trim((string) ($p['email'] ?? '')));
                $name = mb_substr(trim((string) ($p['name'] ?? '')) ?: $email, 0, 100);
                if ($erpId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $seen[] = $erpId;
                $u = $this->db->one('SELECT * FROM talk_users WHERE erp_user_id = ? AND is_bot = 0 LIMIT 1', [$erpId])
                    ?? $this->db->one('SELECT * FROM talk_users WHERE email = ? AND erp_user_id IS NULL AND is_bot = 0', [$email]);
                if ($u === null) {
                    if ($this->db->one('SELECT id FROM talk_users WHERE email = ?', [$email])) {
                        continue; // e-mail já usado por outro perfil: o supervisor resolve
                    }
                    $id = $this->db->insert('talk_users', [
                        'name' => $name, 'email' => $email, 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                        'erp_user_id' => $erpId, 'active' => 1,
                    ]);
                    $this->audit($actorId ?? $id, 'erp_sync_add', ['erp_user_id' => $erpId, 'user_id' => $id], $ip);
                    $out['added']++;
                    continue;
                }
                $taken = $email !== $u['email'] && $this->db->one('SELECT id FROM talk_users WHERE email = ? AND id <> ?', [$email, (int) $u['id']]);
                if ((int) ($u['erp_user_id'] ?? 0) !== $erpId || $u['name'] !== $name || (!$taken && $u['email'] !== $email)) {
                    $this->db->run('UPDATE talk_users SET erp_user_id = ?, name = ?, email = ? WHERE id = ?',
                        [$erpId, $name, $taken ? $u['email'] : $email, (int) $u['id']]);
                    $out['updated']++;
                }
            }
            $gone = $this->db->all(
                'SELECT id, erp_user_id FROM talk_users WHERE active = 1 AND is_bot = 0 AND erp_user_id IS NOT NULL'
                . ($seen ? ' AND erp_user_id NOT IN (' . implode(',', array_map('intval', $seen)) . ')' : '')
            );
            foreach ($gone as $g) {
                $this->db->run('UPDATE talk_users SET active = 0 WHERE id = ?', [(int) $g['id']]);
                // os atendimentos abertos dela voltam para a fila do setor
                $this->db->run("UPDATE talk_conversations SET assigned_user_id = NULL, status = 'pending', queued_at = NOW() WHERE assigned_user_id = ? AND status = 'open'", [(int) $g['id']]);
                $this->audit($actorId ?? (int) $g['id'], 'erp_sync_off', ['erp_user_id' => (int) $g['erp_user_id'], 'user_id' => (int) $g['id']], $ip);
                $out['deactivated']++;
            }
            return $out;
        });
    }

    private function audit(int $userId, string $action, array $details, ?string $ip): void
    {
        $this->db->insert('talk_audit_log', [
            'user_id' => $userId, 'action' => $action, 'target_type' => 'user', 'target_id' => $userId,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'ip' => $ip,
        ]);
    }
}
