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
}
