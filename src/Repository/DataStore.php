<?php
declare(strict_types=1);

namespace App\Repository;

/**
 * Contrato de acesso a dados. Implementações: OracleStore (Oracle 19c / oci8) e JsonStore (demo).
 *
 * Comunicação: ['id'=>int,'date'=>'AAAA-MM-DD','department'=>string,'title'=>string,'url'=>?string,'highlighted'=>bool]
 */
interface DataStore
{
    public function name(): string;

    /** @return array{ok:bool,detail:string} */
    public function ping(): array;

    /** @return list<array<string,mixed>> */
    public function communications(): array;

    /** @return array<string,mixed>|null */
    public function communication(int $id): ?array;

    /**
     * @param array{date:string,department:string,title:string,url:?string,highlighted:bool} $data
     * @return array<string,mixed>
     */
    public function createCommunication(array $data): array;

    /**
     * @param array{date:string,department:string,title:string,url:?string,highlighted:bool} $data
     * @return array<string,mixed>|null null se não existir
     */
    public function updateCommunication(int $id, array $data): ?array;

    public function deleteCommunication(int $id): bool;

    /** @return list<array{id:int,title:string,url:?string}> */
    public function facts(): array;
}
