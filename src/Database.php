<?php
declare(strict_types=1);

namespace App;

use App\Http\HttpException;

/**
 * Wrapper fino sobre oci8. Todas as queries usam bind variables (:nome) – nunca concatenar valores no SQL.
 */
final class Database
{
    /** @var resource|null */
    private $conn = null;

    /** @param array{host:string,port:string,service:string,user:string,password:string,charset:string} $cfg */
    public function __construct(private readonly array $cfg)
    {
    }

    /** @return resource */
    private function conn()
    {
        if ($this->conn !== null) {
            return $this->conn;
        }
        if (!function_exists('oci_connect')) {
            throw new HttpException(503, 'Extensão oci8 não está instalada neste PHP.');
        }
        $c = $this->cfg;
        $dsn = sprintf('//%s:%s/%s', $c['host'], $c['port'], $c['service']);
        $conn = @oci_connect($c['user'], $c['password'], $dsn, $c['charset']);
        if ($conn === false) {
            $e = oci_error();
            error_log('Oracle connect error: ' . ($e['message'] ?? 'desconhecido'));
            throw new HttpException(503, 'Base de dados Oracle indisponível.');
        }
        return $this->conn = $conn;
    }

    /**
     * SELECT → lista de linhas (chaves em minúsculas).
     * @param array<string,scalar|null> $binds nome (sem ':') => valor
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $binds = []): array
    {
        $stmt = $this->run($sql, $binds, false);
        oci_fetch_all($stmt, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
        oci_free_statement($stmt);
        return array_map(static fn (array $r): array => array_change_key_case($r, CASE_LOWER), $rows);
    }

    /** @param array<string,scalar|null> $binds
     *  @return array<string,mixed>|null */
    public function fetchOne(string $sql, array $binds = []): ?array
    {
        return $this->fetchAll($sql, $binds)[0] ?? null;
    }

    /**
     * INSERT/UPDATE/DELETE com commit. Devolve linhas afetadas.
     * $returning: nomes de bind de saída inteiros (ex.: "RETURNING id INTO :new_id") → preenchidos por referência.
     * @param array<string,scalar|null> $binds
     * @param array<string,int> $returning
     */
    public function execute(string $sql, array $binds = [], array &$returning = []): int
    {
        $stmt = $this->run($sql, $binds, true, $returning);
        $n = oci_num_rows($stmt);
        oci_free_statement($stmt);
        return $n;
    }

    public function ping(): bool
    {
        return $this->fetchOne('SELECT 1 AS ok FROM dual') !== null;
    }

    /**
     * @param array<string,scalar|null> $binds
     * @param array<string,int> $out
     * @return resource
     */
    private function run(string $sql, array $binds, bool $commit, array &$out = [])
    {
        $conn = $this->conn(); // falha com 503 se oci8 não existir (antes de usar constantes OCI_*)
        $mode = $commit ? OCI_COMMIT_ON_SUCCESS : OCI_NO_AUTO_COMMIT;
        $stmt = oci_parse($conn, $sql);
        if ($stmt === false) {
            $this->fail($conn);
        }
        foreach ($binds as $name => $value) {
            oci_bind_by_name($stmt, ':' . $name, $binds[$name]);
        }
        foreach ($out as $name => $_) {
            $out[$name] = 0;
            oci_bind_by_name($stmt, ':' . $name, $out[$name], -1, SQLT_INT);
        }
        if (!@oci_execute($stmt, $mode)) {
            $this->fail($stmt);
        }
        return $stmt;
    }

    /** @param resource $res */
    private function fail($res): never
    {
        $e = oci_error($res);
        error_log('Oracle error: ' . ($e['message'] ?? 'desconhecido') . ' | SQL: ' . ($e['sqltext'] ?? ''));
        throw new HttpException(500, 'Erro de base de dados.', Config::debug() ? ['oracle' => (string) ($e['message'] ?? '')] : []);
    }
}
