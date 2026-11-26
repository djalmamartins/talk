<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\Notifications;

/**
 * Pedido do Djalma em 22/11/2026: quando o cliente avalia, o atendente recebe um aviso. 4 ou 5 estrelas é
 * parabéns; 3 ou menos é um convite gentil para rever a conversa. Fica no sino por 12 h, só para quem atendeu.
 */
final class RatingNoticeTest extends DbTestCase
{
    private int $ana;
    private int $beto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->db->insert('talk_users', ['name' => 'Ana Ribeiro', 'email' => 'ana@x.com', 'password_hash' => 'x']);
        $this->beto = $this->db->insert('talk_users', ['name' => 'Beto Lima', 'email' => 'beto@x.com', 'password_hash' => 'x']);
        $this->db->insert('talk_staff', ['user_id' => $this->ana, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $this->db->insert('talk_staff', ['user_id' => $this->beto, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
    }

    private function ratedBy(int $userId, string $answer, string $phone = '5531999998888'): int
    {
        $c = $this->routed('quero a segunda via do boleto', $phone);
        $u = $this->agent($userId, 'Financeiro');
        $this->svc->accept($c, $u);
        $this->svc->close($c, $u);
        $this->inbound($answer, $phone);
        return $c;
    }

    private function ratings(int $userId): array
    {
        $bell = (new Notifications($this->db, new AccessPolicy()))->for($this->agent($userId, 'Financeiro'));
        return array_values(array_filter($bell['items'], static fn (array $i): bool => $i['kind'] === 'rating'));
    }

    public function test_cinco_estrelas_e_parabens_para_quem_atendeu(): void
    {
        $c = $this->ratedBy($this->ana, '5 - muito rápida, obrigada!');
        $items = $this->ratings($this->ana);
        $this->assertCount(1, $items);
        $this->assertStringStartsWith('Parabéns! Você recebeu 5 estrelas de Maria', $items[0]['title']);
        $this->assertSame('"muito rápida, obrigada!"', $items[0]['text']);
        $this->assertSame('/?c=' . $c, $items[0]['href']);
        $this->assertSame(5, $items[0]['score']);
        $this->assertSame([], $this->ratings($this->beto), 'só quem atendeu recebe');
    }

    public function test_nota_baixa_convida_a_revisar_sem_bronca(): void
    {
        $this->ratedBy($this->ana, '2');
        $it = $this->ratings($this->ana)[0];
        $this->assertStringStartsWith('Vamos dar uma olhada? Você recebeu 2 estrelas', $it['title']);
        $this->assertStringContainsString('Reveja a conversa', $it['text']);
    }

    public function test_textos_por_nota(): void
    {
        $this->assertStringStartsWith('Muito bom! Você recebeu 4 estrelas', Notifications::ratingText(4, null, null)['title']);
        $this->assertStringContainsString('1 estrela', Notifications::ratingText(1, 'Maria', '')['title']);
        $this->assertStringStartsWith('"péssimo" Reveja', Notifications::ratingText(1, null, 'péssimo')['text']);
    }

    public function test_some_depois_de_12_horas_e_sem_resposta_nao_avisa(): void
    {
        $this->ratedBy($this->ana, '5');
        $this->db->run('UPDATE talk_ratings SET answered_at = NOW() - INTERVAL 13 HOUR');
        $this->assertSame([], $this->ratings($this->ana));

        $c = $this->routed('quero a segunda via do boleto', '5531977776666');
        $u = $this->agent($this->ana, 'Financeiro');
        $this->svc->accept($c, $u);
        $this->svc->close($c, $u); // perguntou, o cliente ainda não respondeu
        $this->assertSame([], $this->ratings($this->ana));
    }
}
