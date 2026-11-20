<?php
declare(strict_types=1);

namespace ConnectTalk\Support;

/** Assinatura curta do atendente nas mensagens: "Maria (Financeiro)" vira "Maria". */
final class Signature
{
    public static function of(?string $name): string
    {
        return explode(' (', (string) $name)[0];
    }
}
