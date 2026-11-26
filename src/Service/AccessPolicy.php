<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Domain\StaffMember;

/**
 * Quem vê o quê:
 * - supervisor: tudo
 * - gerente: tudo dos setores que gerencia
 * - atendente: fila (pendentes) do seu setor + as conversas atribuídas a ele
 * Um setor nunca vê a conversa do outro (a não ser por transferência).
 * Fila geral (1.5.0, sem a Jack): conversa na fila do setor Geral ($generalSector) aparece para todos,
 * e qualquer um pode pegar. Depois de pega, segue as regras acima (é de quem pegou).
 */
final class AccessPolicy
{
    public function __construct(private ?int $generalSector = null)
    {
    }

    private function inGeneralQueue(array $conv): bool
    {
        return $this->generalSector !== null && $conv['sector_id'] !== null
            && (int) $conv['sector_id'] === $this->generalSector && $conv['status'] === 'pending';
    }

    public function canView(StaffMember $u, array $conv): bool
    {
        if ($u->supervisor || $this->inGeneralQueue($conv)) {
            return true;
        }
        $sector = $conv['sector_id'] !== null ? (int) $conv['sector_id'] : null;
        if ($sector !== null && in_array($sector, $u->managerSectors, true)) {
            return true;
        }
        if ($conv['assigned_user_id'] !== null && (int) $conv['assigned_user_id'] === $u->userId) {
            return true;
        }
        return $sector !== null
            && in_array($sector, $u->agentSectors, true)
            && in_array($conv['status'], ['pending', 'closed'], true);
    }

    /** Pode responder/transferir/encerrar? */
    public function canAct(StaffMember $u, array $conv): bool
    {
        if ($u->supervisor || $this->inGeneralQueue($conv)) {
            return true;
        }
        $sector = $conv['sector_id'] !== null ? (int) $conv['sector_id'] : null;
        if ($sector !== null && in_array($sector, $u->managerSectors, true)) {
            return true;
        }
        if ($conv['status'] === 'pending') {
            return $sector !== null && in_array($sector, $u->agentSectors, true);
        }
        return $conv['assigned_user_id'] !== null && (int) $conv['assigned_user_id'] === $u->userId;
    }

    /**
     * Filtro SQL equivalente ao canView (para listas e para o SSE).
     * @return array{0:string, 1:array<string,mixed>}
     */
    public function sqlFilter(StaffMember $u, string $alias = 'c'): array
    {
        if ($u->supervisor) {
            return ['1=1', []];
        }
        $parts = ["$alias.assigned_user_id = :me"];
        $params = ['me' => $u->userId];
        if ($u->managerSectors) {
            $parts[] = "$alias.sector_id IN (" . implode(',', array_map('intval', $u->managerSectors)) . ')';
        }
        if ($this->generalSector !== null) {
            $parts[] = "($alias.sector_id = " . $this->generalSector . " AND $alias.status = 'pending')";
        }
        if ($u->agentSectors) {
            $parts[] = "($alias.sector_id IN (" . implode(',', array_map('intval', $u->agentSectors)) . ") AND $alias.status IN ('pending','closed'))";
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }
}
