<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Support\Db;

/**
 * Acesso do app do Talk (iPhone e Android, docs/app-movel.md).
 *
 * O app não guarda cookie de sessão: cada aparelho recebe um token próprio `tka_` + 64 hex, mandado em
 * `Authorization: Bearer`. Só o hash SHA-256 fica no banco, então quem lê o banco não entra com ele.
 * - Login pelo ERP: o app abre o navegador do sistema em /app/entrar?state=…; depois do login no ERP o Talk devolve ao app
 *   connecttalk://entrar?code=…&state=… com um código de uso único (2 min), trocado aqui pelo token (redeem).
 * - Login com senha do Talk (TALK_LOGIN=senha, laboratório): o app manda e-mail e senha e recebe o token.
 * - O token vence depois de $ttlDays sem uso (cada uso empurra a validade) e pode ser revogado (Sair, ou pela equipe).
 * - Desativar a pessoa no Talk ou tirar o acesso no ERP derruba o app como derruba a web (index.php confere a cada pedido).
 */
final class AppTokens
{
    public const PREFIX = 'tka_';

    public function __construct(private Db $db, private int $ttlDays = 30, private int $codeSeconds = 120)
    {
    }

    public static function validState(string $state): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $state) === 1;
    }

    /** Código de uso único que volta ao app no endereço connecttalk://entrar. */
    public function issueLoginCode(int $userId, string $state): string
    {
        if (!self::validState($state)) {
            throw new DomainError('Pedido de login do app inválido.');
        }
        $code = bin2hex(random_bytes(32));
        $this->db->run('DELETE FROM talk_app_login_codes WHERE expires_at < NOW()');
        $this->db->insert('talk_app_login_codes', [
            'code_hash' => hash('sha256', $code), 'user_id' => $userId, 'state' => $state,
            'expires_at' => date('Y-m-d H:i:s', time() + $this->codeSeconds),
        ]);
        return $code;
    }

    /**
     * Troca o código pelo token. O state precisa ser o mesmo que o app gerou (o app confere o dele também).
     * @return array{token:string, user_id:int}|null
     */
    public function redeem(string $code, string $state, string $device = '', string $platform = ''): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $code) || !self::validState($state)) {
            return null;
        }
        return $this->db->transaction(function () use ($code, $state, $device, $platform): ?array {
            $row = $this->db->one(
                'SELECT * FROM talk_app_login_codes WHERE code_hash = ? AND used_at IS NULL AND expires_at >= NOW() FOR UPDATE',
                [hash('sha256', $code)]
            );
            if (!$row || !hash_equals((string) $row['state'], $state)) {
                return null;
            }
            $this->db->run('UPDATE talk_app_login_codes SET used_at = NOW() WHERE code_hash = ?', [$row['code_hash']]);
            $userId = (int) $row['user_id'];
            if (!$this->db->one('SELECT id FROM talk_users WHERE id = ? AND active = 1 AND is_bot = 0', [$userId])) {
                return null;
            }
            return ['token' => $this->issue($userId, $device, $platform, time()), 'user_id' => $userId];
        });
    }

    /** Token novo para o aparelho. $erpCheckedAt = quando o ERP confirmou o acesso (agora, no login pelo ERP). */
    public function issue(int $userId, string $device = '', string $platform = '', int $erpCheckedAt = 0): string
    {
        $token = self::PREFIX . bin2hex(random_bytes(32));
        $this->db->insert('talk_app_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'device_name' => mb_substr(trim(strip_tags($device)), 0, 100),
            'platform' => in_array($platform, ['ios', 'android'], true) ? $platform : '',
            'erp_checked_at' => $erpCheckedAt,
            'expires_at' => date('Y-m-d H:i:s', time() + $this->ttlDays * 86400),
        ]);
        return $token;
    }

    /**
     * Token válido? Devolve o aparelho e a pessoa; empurra a validade (no máximo uma vez por hora, para não escrever
     * no banco a cada pedido).
     * @return array{id:int, user_id:int, name:string, created_at:int, erp_checked_at:int}|null
     */
    public function authenticate(string $token): ?array
    {
        if (!preg_match('/^' . self::PREFIX . '[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $r = $this->db->one(
            'SELECT t.id, t.user_id, u.name, UNIX_TIMESTAMP(t.created_at) created, t.erp_checked_at,
                    t.last_used_at IS NULL OR t.last_used_at < NOW() - INTERVAL 1 HOUR AS stale
               FROM talk_app_tokens t JOIN talk_users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at >= NOW() AND u.active = 1 AND u.is_bot = 0',
            [hash('sha256', $token)]
        );
        if (!$r) {
            return null;
        }
        if ((int) $r['stale'] === 1) {
            $this->db->run(
                'UPDATE talk_app_tokens SET last_used_at = NOW(), expires_at = ? WHERE id = ?',
                [date('Y-m-d H:i:s', time() + $this->ttlDays * 86400), (int) $r['id']]
            );
        }
        return ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'name' => (string) $r['name'],
            'created_at' => (int) $r['created'], 'erp_checked_at' => (int) $r['erp_checked_at']];
    }

    public function saveErpCheck(int $tokenId, int $at): void
    {
        $this->db->run('UPDATE talk_app_tokens SET erp_checked_at = ? WHERE id = ?', [$at, $tokenId]);
    }

    /** Endereço de notificação do aparelho (Expo). Se o aparelho trocou de pessoa, o endereço sai do token antigo. */
    public function setPushToken(int $tokenId, string $pushToken): void
    {
        $pushToken = trim($pushToken);
        if (!preg_match('/^Expo(nent)?PushToken\[[A-Za-z0-9_-]{10,100}\]$/', $pushToken)) {
            throw new DomainError('Endereço de notificação inválido.');
        }
        $this->db->transaction(function () use ($tokenId, $pushToken): void {
            $this->db->run('UPDATE talk_app_tokens SET push_token = NULL WHERE push_token = ? AND id <> ?', [$pushToken, $tokenId]);
            $this->db->run('UPDATE talk_app_tokens SET push_token = ? WHERE id = ?', [$pushToken, $tokenId]);
        });
    }

    public function clearPushToken(string $pushToken): void
    {
        $this->db->run('UPDATE talk_app_tokens SET push_token = NULL WHERE push_token = ?', [$pushToken]);
    }

    public function revoke(int $tokenId): void
    {
        $this->db->run('UPDATE talk_app_tokens SET revoked_at = NOW(), push_token = NULL WHERE id = ? AND revoked_at IS NULL', [$tokenId]);
    }

    /** Todos os aparelhos da pessoa saem (ex.: desativada na Equipe). */
    public function revokeUser(int $userId): void
    {
        $this->db->run('UPDATE talk_app_tokens SET revoked_at = NOW(), push_token = NULL WHERE user_id = ? AND revoked_at IS NULL', [$userId]);
    }

    /**
     * Aparelhos que recebem notificação, por pessoa.
     * @return array<int, list<string>> user_id => endereços Expo
     */
    public function pushTargets(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT t.user_id, t.push_token FROM talk_app_tokens t JOIN talk_users u ON u.id = t.user_id
              WHERE t.push_token IS NOT NULL AND t.revoked_at IS NULL AND t.expires_at >= NOW() AND u.active = 1'
        ) as $r) {
            $out[(int) $r['user_id']][] = (string) $r['push_token'];
        }
        return $out;
    }
}
