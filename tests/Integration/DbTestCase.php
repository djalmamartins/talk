<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Erp\NullHelpdesk;
use ConnectTalk\Gateway\GatewayFactory;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Support\Crypto;
use ConnectTalk\Support\Db;
use ConnectTalk\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase
{
    protected Db $db;
    protected Crypto $crypto;
    protected FakeHttpClient $http;
    protected ConversationService $svc;
    protected int $channelId;
    protected array $sectors = [];
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->db = Db::connect((string) getenv('TEST_DB_DSN'), (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASS'));
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query("SHOW TABLES LIKE 'talk\\_%'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        foreach (glob(__DIR__ . '/../../migrations/*.sql') as $file) {
            $pdo->exec((string) file_get_contents($file));
        }
        $this->crypto = new Crypto(str_repeat('k', 32));
        $this->http = new FakeHttpClient();
        $this->channelId = $this->db->insert('talk_channels', [
            'name' => 'Atendimento', 'kind' => 'qrcode', 'purpose' => 'attendance',
            'config_encrypted' => $this->crypto->encrypt(['base_url' => 'http://evo:8080', 'instance' => 'connect', 'api_key' => 'k', 'webhook_secret' => 's3cret']),
        ]);
        foreach ([['Financeiro', '1', 'boleto, pagamento, segunda via, taxa'], ['Administrativo', '2', 'assembleia, reserva, mudança'], ['Manutenção', '3', 'vazamento, elevador, portão, lâmpada']] as [$n, $k, $kw]) {
            $this->sectors[$n] = $this->db->insert('talk_sectors', ['name' => $n, 'menu_key' => $k, 'keywords' => $kw]);
        }
        $this->svc = $this->makeService();
    }

    protected function makeService(?ContactDirectory $dir = null, ?Helpdesk $hd = null): ConversationService
    {
        return new ConversationService(
            $this->db,
            new EventBus($this->db),
            new AccessPolicy(),
            $dir ?? new \ConnectTalk\Erp\NullContactDirectory(),
            $hd ?? new NullHelpdesk(),
            new \ConnectTalk\Service\Texts(),
            new \ConnectTalk\Jack\Jack($this->db),
        );
    }

    /** Roda a triagem da Jack como se a pessoa tivesse parado de escrever há $seconds segundos. */
    protected function triage(int $seconds = 120): int
    {
        return $this->svc->triage($seconds);
    }

    /** Mensagem do cliente + triagem: a conversa já sai na fila do setor. */
    protected function routed(string $text, string $phone = '5531999998888'): int
    {
        $id = (int) $this->inbound($text, $phone);
        $this->triage();
        return $id;
    }

    protected function gateways(): GatewayFactory
    {
        return new GatewayFactory($this->db, $this->crypto, $this->http);
    }

    protected function inbound(string $text, string $phone = '5531999998888', ?string $id = null): ?int
    {
        return $this->svc->handleInbound($this->channelId, new InboundMessage($id ?? 'IN' . (++$this->seq), $phone, 'text', $text, 'Maria Silva'));
    }

    protected function agent(int $userId, string $sector): StaffMember
    {
        return new StaffMember($userId, false, [$this->sectors[$sector]]);
    }

    protected function conv(int $id): array
    {
        return $this->db->one('SELECT * FROM talk_conversations WHERE id = ?', [$id]);
    }

    protected function lastOutbound(int $convId): string
    {
        return (string) $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' ORDER BY id DESC LIMIT 1", [$convId])['body'];
    }
}
