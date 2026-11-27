<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Http\HttpClient;
use ConnectTalk\Support\Db;

/**
 * Notificações no celular (app do Talk, docs/app-movel.md), pelo serviço de push do Expo, que entrega pela Apple (APNs)
 * e pelo Google (FCM). Roda no worker: lê os eventos novos de talk_events (os mesmos do tempo real da web) e avisa:
 * - mensagem do cliente numa conversa que é da pessoa;
 * - conversa nova na fila que a pessoa pode pegar (setor dela ou fila Geral; supervisor e gerente também);
 * - conversa transferida para a pessoa, ou para a fila de um setor dela.
 * Quem fez a ação não é avisado. O que aparece na tela bloqueada segue PUSH_PREVIEW:
 *   nome (padrão) = só o nome do cliente; texto = nome e começo da mensagem; nada = só "Nova mensagem no Talk".
 * O texto do cliente passa pela Apple/Google/Expo só com PUSH_PREVIEW=texto (LGPD: decisão do responsável).
 */
final class PushNotifier
{
    private const SETTING = 'push_last_event';

    public function __construct(
        private Db $db,
        private HttpClient $http,
        private AccessPolicy $policy,
        private StaffRepository $staff,
        private AppTokens $tokens,
        private string $preview = 'nome',
        private string $url = 'https://exp.host/--/api/v2/push/send',
        private string $accessToken = '',
    ) {
    }

    /** @return int quantas notificações foram enviadas */
    public function run(int $limit = 200): int
    {
        $last = $this->lastId();
        if ($last === null) {
            // primeira vez: começa do agora, sem avisar o histórico
            $max = (int) ($this->db->one('SELECT COALESCE(MAX(id), 0) m FROM talk_events')['m']);
            $this->saveLastId($max);
            return 0;
        }
        $rows = $this->db->all(
            'SELECT e.id, e.type, e.user_id AS actor_id, e.payload, e.conversation_id,
                    c.sector_id, c.assigned_user_id, c.status, ct.name AS contact_name, ct.phone, s.name AS sector_name
               FROM talk_events e
               JOIN talk_conversations c ON c.id = e.conversation_id
               JOIN talk_contacts ct ON ct.id = c.contact_id
               LEFT JOIN talk_sectors s ON s.id = c.sector_id
              WHERE e.id > ? ORDER BY e.id LIMIT ' . (int) $limit,
            [$last]
        );
        $maxId = (int) ($this->db->one('SELECT COALESCE(MAX(id), ?) m FROM (SELECT id FROM talk_events WHERE id > ? ORDER BY id LIMIT ' . (int) $limit . ') x', [$last, $last])['m']);
        if ($rows === []) {
            if ($maxId > $last) {
                $this->saveLastId($maxId);
            }
            return 0;
        }
        $targets = $this->tokens->pushTargets();
        $messages = [];
        if ($targets !== []) {
            $members = [];
            foreach (array_keys($targets) as $uid) {
                $members[$uid] = $this->staff->find($uid);
            }
            foreach ($rows as $r) {
                foreach ($members as $uid => $m) {
                    $note = $this->noteFor($r, $m);
                    if ($note === null) {
                        continue;
                    }
                    foreach ($targets[$uid] as $to) {
                        $messages[] = ['to' => $to, 'title' => $note[0], 'body' => $note[1], 'sound' => 'default',
                            'channelId' => 'atendimento', 'priority' => 'high',
                            'data' => ['conversation_id' => (int) $r['conversation_id'], 'kind' => $note[2]]];
                    }
                }
            }
        }
        $sent = $messages === [] ? 0 : $this->send($messages);
        $this->saveLastId(max($maxId, (int) end($rows)['id']));
        return $sent;
    }

    /**
     * O que avisar para a pessoa sobre este evento.
     * @return array{0:string,1:string,2:string}|null [título, texto, tipo]
     */
    public function noteFor(array $r, \ConnectTalk\Domain\StaffMember $m): ?array
    {
        if ($r['actor_id'] !== null && (int) $r['actor_id'] === $m->userId) {
            return null; // foi a própria pessoa
        }
        $data = json_decode((string) $r['payload'], true) ?: [];
        $mine = $r['assigned_user_id'] !== null && (int) $r['assigned_user_id'] === $m->userId;
        $inQueue = $r['status'] === 'pending' && $r['assigned_user_id'] === null && $r['sector_id'] !== null;
        $name = trim((string) ($r['contact_name'] ?? '')) ?: $this->phone((string) $r['phone']);
        $sector = (string) ($r['sector_name'] ?? '');

        switch ($r['type']) {
            case 'message.created':
                if (($data['direction'] ?? '') !== 'in' || !$mine || $r['status'] !== 'open') {
                    return null;
                }
                return $this->message($name, $data);
            case 'conversation.created':
                if (!$inQueue || !$this->policy->canView($m, $r)) {
                    return null;
                }
                return $this->hide('Novo na fila' . ($sector !== '' ? " · $sector" : ''), $name, 'queue');
            case 'conversation.transferred':
                if ($mine) {
                    return $this->hide('Transferido para você', $name, 'transfer');
                }
                if ($inQueue && $this->policy->canView($m, $r)) {
                    return $this->hide('Transferido para a fila' . ($sector !== '' ? " · $sector" : ''), $name, 'queue');
                }
                return null;
        }
        return null;
    }

    /** @return array{0:string,1:string,2:string} */
    private function message(string $name, array $data): array
    {
        if ($this->preview === 'nada') {
            return ['Connect Talk', 'Nova mensagem no Talk', 'message'];
        }
        if ($this->preview !== 'texto') {
            return ['Nova mensagem', $name, 'message'];
        }
        $type = (string) ($data['type'] ?? 'text');
        $body = $type === 'text' ? trim((string) ($data['body'] ?? '')) : match ($type) {
            'image' => 'Foto', 'audio' => 'Áudio', 'video' => 'Vídeo', 'rating' => 'Avaliação', default => 'Arquivo',
        };
        if (mb_strlen($body) > 120) {
            $body = mb_substr($body, 0, 119) . '…';
        }
        return [$name, $body !== '' ? $body : 'Nova mensagem', 'message'];
    }

    /** @return array{0:string,1:string,2:string} */
    private function hide(string $title, string $name, string $kind): array
    {
        return $this->preview === 'nada' ? [$title, 'Abra o Talk para ver', $kind] : [$title, $name, $kind];
    }

    private function phone(string $digits): string
    {
        return $digits === '' ? 'Cliente' : '+' . $digits;
    }

    /** Envia em lotes de 100 (limite do Expo). Aparelho desinstalado (DeviceNotRegistered) sai da lista. */
    private function send(array $messages): int
    {
        $headers = [];
        if ($this->accessToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }
        $ok = 0;
        foreach (array_chunk($messages, 100) as $chunk) {
            try {
                $r = $this->http->postJson($this->url, $chunk, $headers);
            } catch (\Throwable $e) {
                error_log('[talk] push: ' . $e->getMessage());
                continue;
            }
            if ($r['status'] < 200 || $r['status'] >= 300) {
                error_log('[talk] push: HTTP ' . $r['status'] . ' ' . mb_substr($r['body'], 0, 300));
                continue;
            }
            $tickets = json_decode($r['body'], true)['data'] ?? [];
            foreach ($chunk as $i => $msg) {
                $t = $tickets[$i] ?? null;
                if (($t['status'] ?? '') === 'ok') {
                    $ok++;
                } elseif (($t['details']['error'] ?? '') === 'DeviceNotRegistered') {
                    $this->tokens->clearPushToken($msg['to']);
                }
            }
        }
        return $ok;
    }

    private function lastId(): ?int
    {
        $r = $this->db->one('SELECT value FROM talk_settings WHERE name = ?', [self::SETTING]);
        return $r === null ? null : (int) json_decode((string) $r['value'], true);
    }

    private function saveLastId(int $id): void
    {
        $this->db->run(
            'INSERT INTO talk_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [self::SETTING, json_encode($id)]
        );
    }
}
