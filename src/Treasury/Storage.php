<?php
declare(strict_types=1);

namespace App\Treasury;

use App\Config;
use App\Http\HttpException;

/**
 * Armazenamento privado do plano de tesouraria (ficheiros JSON + ficheiro Sage) fora do DocumentRoot.
 *   <privado>/sage-open-items.xls|xlsx   ficheiro Sage original (confidencial)
 *   <privado>/sage-items.json            cache dos itens lidos (confidencial)
 *   <privado>/treasury.json              bancos, definições, entradas manuais, recorrentes, alterações (confidencial)
 *   <privado>/clientes-bancos.xlsx|xls   relação cliente → banco (BPCNUM_0, DES_0) (confidencial)
 *   <privado>/clientes-bancos.json       cache da relação cliente → banco (confidencial)
 * Sem ficheiro Sage privado → modo demonstração com data/sample (dados fictícios).
 */
final class Storage
{
    public const SAGE_BASENAME = 'sage-open-items';
    public const CLI_BASENAME = 'clientes-bancos';

    public function __construct(private readonly string $root)
    {
    }

    public function privateDir(): string
    {
        $d = Config::env('TREASURY_DATA_DIR', $this->root . '/data/private');
        return rtrim($d, '/');
    }

    private function sampleDir(): string
    {
        return $this->root . '/data/sample';
    }

    public function ensureDir(): void
    {
        $d = $this->privateDir();
        if (!is_dir($d) && !@mkdir($d, 0770, true) && !is_dir($d)) {
            throw new HttpException(500, 'Não foi possível criar a pasta privada de dados.');
        }
        if (!is_writable($d)) {
            throw new HttpException(500, 'A pasta privada de dados não tem permissão de escrita.');
        }
    }

    /** Ficheiro Sage privado existente (.xls/.xlsx) ou null. */
    public function sageFile(): ?string
    {
        foreach (['xls', 'xlsx'] as $ext) {
            $f = $this->privateDir() . '/' . self::SAGE_BASENAME . '.' . $ext;
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }

    public function isDemo(): bool
    {
        return $this->sageFile() === null && !is_file($this->privateDir() . '/sage-items.json');
    }

    /**
     * Itens Sage (cache JSON; reimporta automaticamente se o ficheiro .xls mudou).
     * @return array<string,mixed>
     */
    public function loadSage(): array
    {
        $dir = $this->privateDir();
        $json = $dir . '/sage-items.json';
        $xls = $this->sageFile();
        if ($xls !== null) {
            $cached = is_file($json) ? $this->readJson($json) : null;
            $fresh = $cached !== null
                && ($cached['sourceMtime'] ?? null) === filemtime($xls)
                && ($cached['sourceSize'] ?? null) === filesize($xls)
                && ($cached['sourceName'] ?? null) === basename($xls);
            if ($fresh) {
                return $cached;
            }
            $this->ensureDir();
            return $this->withLock(function () use ($xls, $json): array {
                $data = SageImporter::parse($xls);
                $this->writeJson($json, $data);
                return $data;
            });
        }
        if (is_file($json)) {
            $d = $this->readJson($json);
            if ($d !== null) {
                return $d;
            }
        }
        $sample = $this->readJson($this->sampleDir() . '/sage-items.json');
        if ($sample === null) {
            throw new HttpException(500, 'Dados de demonstração em falta.');
        }
        $sample['demo'] = true;
        return $sample;
    }

    /** Substitui o ficheiro Sage (valida primeiro; guarda cópia .bak do anterior). @return array<string,mixed> */
    public function replaceSage(string $uploadedTmp, string $ext): array
    {
        $this->ensureDir();
        $dir = $this->privateDir();
        return $this->withLock(function () use ($uploadedTmp, $ext, $dir): array {
            $tmp = $dir . '/upload-' . bin2hex(random_bytes(6)) . '.tmp.' . $ext;
            if (!@move_uploaded_file($uploadedTmp, $tmp) && !@copy($uploadedTmp, $tmp)) {
                throw new HttpException(500, 'Não foi possível guardar o ficheiro carregado.');
            }
            @chmod($tmp, 0660);
            try {
                $data = SageImporter::parse($tmp); // lança HttpException 422 se inválido
            } catch (\Throwable $e) {
                @unlink($tmp);
                throw $e;
            }
            $old = $this->sageFile();
            if ($old !== null) {
                @rename($old, $old . '.bak');
            }
            foreach (['xls', 'xlsx'] as $x) {
                $f = $dir . '/' . self::SAGE_BASENAME . '.' . $x;
                if ($x !== $ext && is_file($f)) {
                    @rename($f, $f . '.bak');
                }
            }
            $dest = $dir . '/' . self::SAGE_BASENAME . '.' . $ext;
            if (!@rename($tmp, $dest)) {
                @unlink($tmp);
                throw new HttpException(500, 'Não foi possível guardar o ficheiro Sage.');
            }
            $data['sourceName'] = basename($dest);
            $data['sourceMtime'] = filemtime($dest);
            $data['sourceSize'] = filesize($dest);
            $this->writeJson($dir . '/sage-items.json', $data);
            return $data;
        });
    }

    // ------------------------------------------------------------------ relação cliente → banco

    public function clientesFile(): ?string
    {
        foreach (['xlsx', 'xls'] as $ext) {
            $f = $this->privateDir() . '/' . self::CLI_BASENAME . '.' . $ext;
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Relação cliente → banco (cache JSON; reimporta se o ficheiro mudou). Sem ficheiro privado: amostra no modo demo, senão vazia.
     * @return array<string,mixed> map = código → nome do banco; source = 'privado'|'amostra'|'nenhum'
     */
    public function loadClientes(bool $demo): array
    {
        $dir = $this->privateDir();
        $json = $dir . '/' . self::CLI_BASENAME . '.json';
        $f = $this->clientesFile();
        if ($f !== null) {
            $cached = is_file($json) ? $this->readJson($json) : null;
            if ($cached !== null && ($cached['sourceMtime'] ?? null) === filemtime($f) && ($cached['sourceSize'] ?? null) === filesize($f)
                && ($cached['sourceName'] ?? null) === basename($f)) {
                return $cached + ['source' => 'privado'];
            }
            $this->ensureDir();
            return $this->withLock(function () use ($f, $json): array {
                $d = ClientesImporter::parse($f);
                $d['sourceName'] = basename($f);
                $d['sourceMtime'] = filemtime($f);
                $d['sourceSize'] = filesize($f);
                $this->writeJson($json, $d);
                return $d + ['source' => 'privado'];
            });
        }
        if ($demo) {
            $d = $this->readJson($this->sampleDir() . '/clientes-bancos.json');
            if ($d !== null) {
                return $d + ['source' => 'amostra'];
            }
        }
        return ['version' => 1, 'map' => [], 'rows' => 0, 'ignored' => 0, 'warnings' => [], 'source' => 'nenhum', 'sourceName' => null, 'importedAt' => null];
    }

    /** Substitui o ficheiro de clientes e bancos (valida primeiro; guarda cópia .bak). @return array<string,mixed> */
    public function replaceClientes(string $uploadedTmp, string $ext): array
    {
        $this->ensureDir();
        $dir = $this->privateDir();
        return $this->withLock(function () use ($uploadedTmp, $ext, $dir): array {
            $tmp = $dir . '/upload-' . bin2hex(random_bytes(6)) . '.tmp.' . $ext;
            if (!@move_uploaded_file($uploadedTmp, $tmp) && !@copy($uploadedTmp, $tmp)) {
                throw new HttpException(500, 'Não foi possível guardar o ficheiro carregado.');
            }
            @chmod($tmp, 0660);
            try {
                $data = ClientesImporter::parse($tmp);
            } catch (\Throwable $e) {
                @unlink($tmp);
                throw $e;
            }
            foreach (['xlsx', 'xls'] as $x) {
                $f = $dir . '/' . self::CLI_BASENAME . '.' . $x;
                if (is_file($f)) {
                    @rename($f, $f . '.bak');
                }
            }
            $dest = $dir . '/' . self::CLI_BASENAME . '.' . $ext;
            if (!@rename($tmp, $dest)) {
                @unlink($tmp);
                throw new HttpException(500, 'Não foi possível guardar o ficheiro de clientes e bancos.');
            }
            $data['sourceName'] = basename($dest);
            $data['sourceMtime'] = filemtime($dest);
            $data['sourceSize'] = filesize($dest);
            $this->writeJson($dir . '/' . self::CLI_BASENAME . '.json', $data);
            return $data + ['source' => 'privado'];
        });
    }

    private function statePath(bool $demo): string
    {
        return $this->privateDir() . '/' . ($demo ? 'treasury-demo.json' : 'treasury.json');
    }

    /** @return array<string,mixed> */
    public function defaultState(): array
    {
        return [
            'version' => 1,
            'settings' => [
                'inicio' => '', 'horiz' => '10', 'vista' => 'Semanal', 'useCCC' => false, 'netting' => true,
                'incPend' => true, 'incOut' => true, 'incRec' => true, 'ruleR' => '0', 'ruleP' => '0',
                'defBankR' => '', 'defBankP' => '', 'modoP' => 'Sugerido', 'crit' => 'Maior folga',
            ],
            'banks' => [],
            'partyBank' => new \stdClass(),
            'classOv' => new \stdClass(),
            'prio' => [],
            'recs' => [],
            'cliSig' => null,
            'manual' => [],
            'overrides' => new \stdClass(),
        ];
    }

    /** @return array<string,mixed> */
    public function loadState(bool $demo): array
    {
        $p = $this->statePath($demo);
        $st = is_file($p) ? $this->readJson($p) : null;
        if ($st === null) {
            $st = $demo ? ($this->readJson($this->sampleDir() . '/treasury.json') ?? $this->defaultState()) : $this->defaultState();
        }
        $def = $this->defaultState();
        $set = (array) ($st['settings'] ?? []);
        if (isset($set['defBank']) && $set['defBank'] !== '') { // versões anteriores: um só banco por defeito para os dois lados
            $set['defBankR'] = $set['defBankR'] ?? $set['defBank'];
            $set['defBankP'] = $set['defBankP'] ?? $set['defBank'];
        }
        unset($set['defBank'], $set['crossNet']);
        $st['settings'] = array_merge($def['settings'], $set);
        $st['classOv'] = (array) ($st['classOv'] ?? []);
        $st['prio'] = array_values((array) ($st['prio'] ?? []));
        $st['recs'] = array_values((array) ($st['recs'] ?? []));
        $st['cliSig'] = $st['cliSig'] ?? null;
        $st['banks'] = array_values((array) ($st['banks'] ?? []));
        $st['manual'] = array_values((array) ($st['manual'] ?? []));
        $st['partyBank'] = (array) ($st['partyBank'] ?? []);
        $st['overrides'] = (array) ($st['overrides'] ?? []);
        return $st;
    }

    /**
     * Lê-modifica-grava o estado com bloqueio.
     * @param callable(array<string,mixed>):mixed $fn recebe o estado por valor e devolve [novoEstado, resultado]
     */
    public function mutateState(bool $demo, callable $fn): mixed
    {
        $this->ensureDir();
        return $this->withLock(function () use ($demo, $fn): mixed {
            $st = $this->loadState($demo);
            [$new, $result] = $fn($st);
            $this->writeJson($this->statePath($demo), $this->normalizeForSave($new));
            return $result;
        });
    }

    /** @param array<string,mixed> $st @return array<string,mixed> */
    private function normalizeForSave(array $st): array
    {
        $st['partyBank'] = $st['partyBank'] === [] ? new \stdClass() : $st['partyBank'];
        $st['overrides'] = $st['overrides'] === [] ? new \stdClass() : $st['overrides'];
        $st['classOv'] = ($st['classOv'] ?? []) === [] ? new \stdClass() : $st['classOv'];
        $st['prio'] = array_values($st['prio'] ?? []);
        $st['recs'] = array_values($st['recs'] ?? []);
        $st['banks'] = array_values($st['banks']);
        $st['manual'] = array_values($st['manual']);
        return $st;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            throw new HttpException(500, 'Não foi possível gravar os dados.');
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new HttpException(500, 'Não foi possível gravar os dados.');
        }
    }

    private function withLock(callable $fn): mixed
    {
        $this->ensureDir();
        $lock = fopen($this->privateDir() . '/.lock', 'c');
        if ($lock === false) {
            throw new HttpException(500, 'Não foi possível obter o bloqueio de escrita.');
        }
        try {
            flock($lock, LOCK_EX);
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
