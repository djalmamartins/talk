<?php
declare(strict_types=1);

namespace ConnectTalk\Erp;

use ConnectTalk\Contracts\ContactDirectory;

/** Enquanto não ligamos no ERP: ninguém é identificado. */
final class NullContactDirectory implements ContactDirectory
{
    public function findByPhone(array $phoneCandidates): ?array
    {
        return null;
    }
}
