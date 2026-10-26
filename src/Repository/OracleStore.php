<?php
declare(strict_types=1);

namespace App\Repository;

use App\Database;

/** Repositório Oracle 19c (oci8, bind variables, colunas IDENTITY). Ver db/schema.sql. */
final class OracleStore implements DataStore
{
    private const COMM_COLS = "id, TO_CHAR(published_on, 'YYYY-MM-DD') AS published_on, department, title, url, highlighted";

    public function __construct(private readonly Database $db)
    {
    }

    public function name(): string
    {
        return 'oracle';
    }

    public function ping(): array
    {
        try {
            $this->db->ping();
            return ['ok' => true, 'detail' => 'Oracle ligado'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    public function communications(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COMM_COLS . ' FROM communications ORDER BY published_on DESC, id DESC'
        );
        return array_map([$this, 'mapComm'], $rows);
    }

    public function communication(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT ' . self::COMM_COLS . ' FROM communications WHERE id = :id',
            ['id' => $id]
        );
        return $row === null ? null : $this->mapComm($row);
    }

    public function createCommunication(array $data): array
    {
        $ret = ['new_id' => 0];
        $this->db->execute(
            "INSERT INTO communications (published_on, department, title, url, highlighted)
             VALUES (TO_DATE(:published_on, 'YYYY-MM-DD'), :department, :title, :url, :highlighted)
             RETURNING id INTO :new_id",
            [
                'published_on' => $data['date'],
                'department' => $data['department'],
                'title' => $data['title'],
                'url' => $data['url'],
                'highlighted' => $data['highlighted'] ? 1 : 0,
            ],
            $ret
        );
        return ['id' => $ret['new_id']] + $data;
    }

    public function updateCommunication(int $id, array $data): ?array
    {
        $n = $this->db->execute(
            "UPDATE communications
                SET published_on = TO_DATE(:published_on, 'YYYY-MM-DD'),
                    department = :department, title = :title, url = :url, highlighted = :highlighted
              WHERE id = :id",
            [
                'published_on' => $data['date'],
                'department' => $data['department'],
                'title' => $data['title'],
                'url' => $data['url'],
                'highlighted' => $data['highlighted'] ? 1 : 0,
                'id' => $id,
            ]
        );
        return $n > 0 ? ['id' => $id] + $data : null;
    }

    public function deleteCommunication(int $id): bool
    {
        return $this->db->execute('DELETE FROM communications WHERE id = :id', ['id' => $id]) > 0;
    }

    public function facts(): array
    {
        $rows = $this->db->fetchAll('SELECT id, title, url FROM facts WHERE active = 1 ORDER BY sort_order, id');
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'url' => $r['url'] === null ? null : (string) $r['url'],
        ], $rows);
    }

    /** @param array<string,mixed> $r
     *  @return array<string,mixed> */
    private function mapComm(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'date' => (string) $r['published_on'],
            'department' => (string) $r['department'],
            'title' => (string) $r['title'],
            'url' => $r['url'] === null ? null : (string) $r['url'],
            'highlighted' => (int) $r['highlighted'] === 1,
        ];
    }
}
