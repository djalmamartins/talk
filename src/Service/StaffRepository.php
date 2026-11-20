<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Support\Db;

final class StaffRepository
{
    public function __construct(private Db $db)
    {
    }

    public function find(int $userId): StaffMember
    {
        $rows = $this->db->all('SELECT sector_id, role FROM talk_staff WHERE user_id = ?', [$userId]);
        $sup = false;
        $agent = $manager = [];
        foreach ($rows as $r) {
            if ($r['role'] === 'supervisor') {
                $sup = true;
            } elseif ($r['role'] === 'manager' && $r['sector_id'] !== null) {
                $manager[] = (int) $r['sector_id'];
            } elseif ($r['sector_id'] !== null) {
                $agent[] = (int) $r['sector_id'];
            }
        }
        return new StaffMember($userId, $sup, $agent, $manager);
    }

    /** Nomes, setores ativos e quem atende cada setor (usado no /api/me e em "Trocar atendente"). */
    public function directory(): array
    {
        $names = [];
        foreach ($this->db->all('SELECT id, name FROM talk_users') as $r) {
            $names[(int) $r['id']] = $r['name'];
        }
        return [
            'sectors' => $this->db->all('SELECT id, name, menu_key, color FROM talk_sectors WHERE active = 1 ORDER BY menu_key'),
            'users' => $names,
            'staff' => $this->db->all(
                "SELECT s.user_id, u.name, s.sector_id, s.role FROM talk_staff s JOIN talk_users u ON u.id = s.user_id
                  WHERE u.active = 1 ORDER BY u.name"
            ),
        ];
    }

    public function email(int $userId): string
    {
        return (string) ($this->db->one('SELECT email FROM talk_users WHERE id = ?', [$userId])['email'] ?? '');
    }
}
