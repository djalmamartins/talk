<?php
declare(strict_types=1);

namespace ConnectTalk\Gateway;

use ConnectTalk\Http\HttpClient;
use ConnectTalk\Support\Crypto;
use ConnectTalk\Support\Db;

/** Monta o gateway certo para cada canal (a "chavinha" QR Code ↔ oficial). */
class GatewayFactory
{
    public function __construct(private Db $db, private Crypto $crypto, private HttpClient $http)
    {
    }

    public function forChannel(int $channelId): WhatsAppGateway
    {
        $ch = $this->db->one('SELECT kind, config_encrypted FROM talk_channels WHERE id = ?', [$channelId]);
        if (!$ch) {
            throw new GatewayException("Canal $channelId não existe");
        }
        $cfg = $this->crypto->decrypt($ch['config_encrypted']);
        return match ($ch['kind']) {
            'official' => new CloudApiGateway($cfg, $this->http),
            'qrcode' => new EvolutionGateway($cfg, $this->http),
            'lab' => new LabGateway(),
        };
    }

    /** Canal usado para envios em massa: o oficial, se existir; senão o de atendimento. */
    public function bulkChannelId(): int
    {
        $row = $this->db->one(
            "SELECT id FROM talk_channels WHERE status='active' AND purpose IN ('bulk','both')
             ORDER BY kind='official' DESC, id LIMIT 1"
        ) ?? $this->db->one("SELECT id FROM talk_channels WHERE status='active' ORDER BY id LIMIT 1");
        if (!$row) {
            throw new GatewayException('Nenhum canal ativo');
        }
        return (int) $row['id'];
    }
}
