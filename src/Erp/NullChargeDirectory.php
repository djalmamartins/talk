<?php
declare(strict_types=1);

namespace ConnectTalk\Erp;

use ConnectTalk\Contracts\ChargeDirectory;

/** Sem ERP ligado: não há 2ª via. */
final class NullChargeDirectory implements ChargeDirectory
{
    public function openCharges(int $personId, string $phone): ?array
    {
        return null;
    }
}
