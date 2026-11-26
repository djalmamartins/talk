<?php
declare(strict_types=1);

namespace ConnectTalk\Contracts;

/**
 * 2ª via dos boletos em aberto do morador, no ERP (docs/api-erp.md §6, ADR 0386 do ERP).
 * O ERP só devolve boleto ao proprietário ou responsável financeiro cujo telefone do cadastro é o da conversa.
 */
interface ChargeDirectory
{
    /**
     * @return list<array{id:int, condominium:string, unit:string, competence:string, due_on:string, amount_cents:int,
     *               digitable_line:?string, pix_copy_paste:?string, portal_url:string}>|null
     *         null = ERP não ligado; lista vazia = nenhum boleto (ou o ERP não reconheceu o morador e o telefone)
     */
    public function openCharges(int $personId, string $phone): ?array;
}
