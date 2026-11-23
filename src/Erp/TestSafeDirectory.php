<?php
declare(strict_types=1);

namespace ConnectTalk\Erp;

use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Support\Phone;

/** Trava da carga de teste da Jack: número de teste (Phone::isTest) não é consultado no ERP. */
final class TestSafeDirectory implements ContactDirectory
{
    public function __construct(private ContactDirectory $inner)
    {
    }

    public function findByPhone(array $phoneCandidates): ?array
    {
        foreach ($phoneCandidates as $p) {
            if (Phone::isTest((string) $p)) {
                return null;
            }
        }
        return $this->inner->findByPhone($phoneCandidates);
    }
}
