<?php
declare(strict_types=1);

namespace App\Repository;

use App\Http\HttpException;

/** Modo demo: dados em data/communications.json e data/facts.json. */
final class JsonStore implements DataStore
{
    public function __construct(private readonly string $dir)
    {
    }

    public function name(): string
    {
        return 'json';
    }

    public function ping(): array
    {
        return ['ok' => is_readable($this->dir . '/communications.json'), 'detail' => 'ficheiros JSON (modo demo)'];
    }

    public function communications(): array
    {
        $rows = $this->read('communications.json');
        usort($rows, static fn (array $a, array $b): int => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
        return $rows;
    }

    public function communication(int $id): ?array
    {
        foreach ($this->read('communications.json') as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    public function createCommunication(array $data): array
    {
        $rows = $this->read('communications.json');
        $id = $rows === [] ? 1 : max(array_column($rows, 'id')) + 1;
        $row = ['id' => $id] + $data;
        $rows[] = $row;
        $this->write('communications.json', $rows);
        return $row;
    }

    public function updateCommunication(int $id, array $data): ?array
    {
        $rows = $this->read('communications.json');
        foreach ($rows as $i => $row) {
            if ($row['id'] === $id) {
                $rows[$i] = ['id' => $id] + $data;
                $this->write('communications.json', $rows);
                return $rows[$i];
            }
        }
        return null;
    }

    public function deleteCommunication(int $id): bool
    {
        $rows = $this->read('communications.json');
        $kept = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] !== $id));
        if (count($kept) === count($rows)) {
            return false;
        }
        $this->write('communications.json', $kept);
        return true;
    }

    public function facts(): array
    {
        return $this->read('facts.json');
    }

    /** @return list<array<string,mixed>> */
    private function read(string $file): array
    {
        $path = $this->dir . '/' . $file;
        $json = is_file($path) ? file_get_contents($path) : '[]';
        $data = json_decode($json === false ? '[]' : $json, true);
        return is_array($data) ? array_values($data) : [];
    }

    /** @param list<array<string,mixed>> $rows */
    private function write(string $file, array $rows): void
    {
        $path = $this->dir . '/' . $file;
        $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new HttpException(500, 'Não foi possível escrever em data/ (verifique permissões).');
        }
    }
}
