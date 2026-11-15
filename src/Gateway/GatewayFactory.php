<?php
declare(strict_types=1);

namespace ConnectTalk\Gateway;

use ConnectTalk\Http\HttpClient;
use ConnectTalk\Support\Crypto;
use ConnectTalk\Support\Db;

/** Monta o gateway certo para cada canal (a "chavinha" QR Code ↔ oficial). */
class GatewayFactory
{
    /**
     * @param array{base_url?:string, api_key?:string} $evolution servidor da Evolution (EVOLUTION_URL e
     *        EVOLUTION_API_KEY no .env), usado por todos os números de QR Code que não tenham um próprio
     * @param bool $simulateQr laboratório sem Evolution: o QR Code é simulado (botão "Simular leitura")
     */
    public function __construct(private Db $db, private Crypto $crypto, private HttpClient $http, private array $evolution = [], private bool $simulateQr = false)
    {
        $this->evolution = array_filter($this->evolution, fn ($v) => trim((string) $v) !== '');
    }

    public function evolutionReady(): bool
    {
        return !empty($this->evolution['base_url']) && !empty($this->evolution['api_key']);
    }

    /** Número de QR Code que, aqui, é simulado (laboratório sem Evolution e sem servidor próprio). */
    public function isSimulated(string $kind, array $cfg): bool
    {
        return $kind === 'lab' || ($kind === 'qrcode' && $this->simulateQr && !$this->evolutionReady() && empty($cfg['base_url']));
    }

    public function forChannel(int $channelId): WhatsAppGateway
    {
        $ch = $this->db->one('SELECT kind, config_encrypted FROM talk_channels WHERE id = ?', [$channelId]);
        if (!$ch) {
            throw new GatewayException("Canal $channelId não existe");
        }
        $cfg = $this->crypto->decrypt($ch['config_encrypted']);
        if ($this->isSimulated($ch['kind'], $cfg)) {
            return new LabGateway();
        }
        return match ($ch['kind']) {
            'official' => new CloudApiGateway($cfg, $this->http),
            'qrcode' => new EvolutionGateway($cfg + $this->evolution, $this->http), // o do número vale mais que o do servidor
            'lab' => new LabGateway(),
        };
    }

    public function channelStatus(int $channelId): ?string
    {
        return $this->db->one('SELECT status FROM talk_channels WHERE id = ?', [$channelId])['status'] ?? null;
    }

    public function markState(int $channelId, string $state): void
    {
        $this->db->run('UPDATE talk_channels SET connection_state = ?, state_checked_at = NOW() WHERE id = ?', [$state, $channelId]);
    }

    /** Canal usado para envios em massa: o oficial, se existir; senão o de atendimento. */
    public function bulkChannelId(): int
    {
        $row = $this->db->one(
            "SELECT id FROM talk_channels WHERE status='active' AND purpose IN ('bulk','both')
             ORDER BY kind='official' DESC, id LIMIT 1"
        ) ?? $this->db->one("SELECT id FROM talk_channels WHERE status='active' ORDER BY is_default DESC, id LIMIT 1");
        if (!$row) {
            throw new GatewayException('Nenhum canal ativo');
        }
        return (int) $row['id'];
    }
}
