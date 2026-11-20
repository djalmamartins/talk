<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\Notifications;

final class NotificationsTest extends DbTestCase
{
    private function bell(StaffMember $u): array
    {
        return (new Notifications($this->db, new AccessPolicy()))->for($u);
    }

    public function test_sem_nada_pendente_o_sino_fica_vazio(): void
    {
        $this->assertSame(['count' => 0, 'items' => []], $this->bell(new StaffMember(1, true)));
    }

    public function test_fila_respostas_agendamento_e_numero_caido(): void
    {
        $c = $this->routed('quero a segunda via do boleto'); // vai para a fila do Financeiro
        $sup = new StaffMember(1, true);
        $fin = $this->agent(2, 'Financeiro');
        $man = $this->agent(3, 'Manutenção');

        $kinds = fn (array $b) => array_column($b['items'], 'count', 'kind');
        $this->assertSame(['queue' => 1], $kinds($this->bell($fin)));
        $this->assertSame([], $kinds($this->bell($man))); // outro setor não vê
        $this->assertSame('1 conversa esperando na fila', $this->bell($fin)['items'][0]['title']);

        // a atendente pega a conversa e o cliente manda mais duas mensagens
        $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = 2, unread = 2 WHERE id = ?", [$c]);
        $b = $this->bell($fin);
        $this->assertSame(['unread' => 1], $kinds($b));
        $this->assertSame('2 mensagens novas nos seus atendimentos.', $b['items'][0]['text']);
        $this->assertSame('/?aba=mine', $b['items'][0]['href']);

        // agendamento que falhou (setor Financeiro) e número desconectado (só o supervisor vê)
        $conv = $this->conv($c);
        $this->db->insert('talk_schedules', ['contact_id' => $conv['contact_id'], 'channel_id' => $this->channelId, 'sector_id' => $this->sectors['Financeiro'],
            'body' => 'oi', 'send_at' => date('Y-m-d H:i:s'), 'status' => 'failed', 'created_by' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->db->run("UPDATE talk_channels SET connection_state = 'disconnected' WHERE id = ?", [$this->channelId]);
        $this->assertSame(['unread' => 1, 'schedules' => 1], $kinds($this->bell($fin)));
        $this->assertSame([], $kinds($this->bell($man)));
        $all = $this->bell($sup);
        $this->assertSame(['schedules' => 1, 'channels' => 1], $kinds($all)); // a conversa não é dele
        $this->assertSame(2, $all['count']);
    }

    public function test_transferencia_avisa_quem_recebe_ate_aceitar_ou_abrir(): void
    {
        $kinds = fn (array $b) => array_column($b['items'], 'count', 'kind');
        $c = $this->routed('quero a segunda via do boleto', '5531944444444'); // Financeiro
        $fin = $this->agent(2, 'Financeiro');
        $man = $this->agent(3, 'Manutenção');
        $this->svc->accept($c, $fin);

        // para o setor: todo mundo da Manutenção vê, quem transferiu não
        $this->svc->transfer($c, $fin, $this->sectors['Manutenção'], 'é reparo');
        $b = $this->bell($man);
        $this->assertSame(1, $kinds($b)['transfer'] ?? null);
        $item = array_values(array_filter($b['items'], fn ($i) => $i['kind'] === 'transfer'))[0];
        $this->assertStringContainsString('Conversa transferida para Manutenção', $item['title']);
        $this->assertSame('/?c=' . $c, $item['href']);
        $this->assertArrayNotHasKey('transfer', $kinds($this->bell($fin)));

        // alguém da Manutenção aceita: o aviso some
        $this->svc->accept($c, $man);
        $this->assertArrayNotHasKey('transfer', $kinds($this->bell($man)));

        // para uma pessoa: aparece até ela abrir a conversa
        $this->db->insert('talk_users', ['id' => 2, 'name' => 'Fin', 'email' => 'fin@x.com', 'password_hash' => 'x']);
        $this->db->insert('talk_staff', ['user_id' => 2, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $this->svc->transfer($c, $man, $this->sectors['Financeiro'], null, 2);
        $this->assertSame(1, $kinds($this->bell($fin))['transfer'] ?? null);
        $this->assertStringContainsString('transferida para você', $this->bell($fin)['items'][0]['title']);
        $this->svc->markRead($c, $fin);
        $this->assertArrayNotHasKey('transfer', $kinds($this->bell($fin)));
    }

    public function test_espera_do_sino_nao_conta_o_setor_fechado(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $this->db->run('UPDATE talk_conversations SET queued_at = NOW() - INTERVAL 3 HOUR WHERE id = ?', [$id]);
        $hours = new \ConnectTalk\Service\BusinessHours($this->db);
        $fin = $this->agent(10, 'Financeiro');
        $bell = fn () => (new Notifications($this->db, new AccessPolicy(), $hours))->for($fin)['items'][0]['text'];
        $this->assertSame('A mais antiga espera há 3 horas.', $bell());
        // setor só abre daqui a 3 dias: as últimas 3 horas foram com ele fechado, a espera não corre
        $week = array_fill_keys(array_map('strval', range(0, 6)), []);
        $week[(new \DateTimeImmutable($this->db->one('SELECT NOW() n')['n']))->modify('+3 day')->format('w')] = [['08:00', '12:00']];
        $hours->save(new StaffMember(1, true), ['enabled' => true, 'week' => $week]);
        $this->assertSame('A mais antiga chegou agora.', $bell());
    }
}
