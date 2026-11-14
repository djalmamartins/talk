<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

/** Erro de regra de negócio, com código HTTP sugerido. */
final class DomainError extends \RuntimeException
{
    public function __construct(string $message, int $httpCode = 422)
    {
        parent::__construct($message, $httpCode);
    }
}
