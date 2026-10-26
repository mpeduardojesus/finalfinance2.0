<?php
declare(strict_types=1);

namespace App;

use App\Http\HttpException;

final class Validator
{
    /**
     * Valida e normaliza uma comunicação (usado em POST e PUT; substituição completa).
     * @param array<string,mixed> $in
     * @return array{date:string,department:string,title:string,url:?string,highlighted:bool}
     */
    public static function communication(array $in): array
    {
        $errors = [];

        $date = $in['date'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors['date'] = 'Obrigatório, formato AAAA-MM-DD.';
        } else {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($d === false || $d->format('Y-m-d') !== $date) {
                $errors['date'] = 'Data inválida.';
            }
        }

        $department = self::text($in['department'] ?? null, 100, 'department', $errors);
        $title = self::text($in['title'] ?? null, 300, 'title', $errors);

        $url = $in['url'] ?? null;
        if ($url !== null && $url !== '') {
            if (!is_string($url) || strlen($url) > 500 || !preg_match('#^(https?://|/|\#)#', $url)) {
                $errors['url'] = 'Deve ser http(s)://, caminho iniciado por / ou #, até 500 caracteres.';
            }
        } else {
            $url = null;
        }

        $highlighted = $in['highlighted'] ?? false;
        if (!is_bool($highlighted)) {
            $errors['highlighted'] = 'Deve ser booleano.';
            $highlighted = false;
        }

        if ($errors !== []) {
            throw new HttpException(422, 'Dados inválidos.', $errors);
        }

        return [
            'date' => (string) $date,
            'department' => $department,
            'title' => $title,
            'url' => $url,
            'highlighted' => $highlighted,
        ];
    }

    /** @param array<string,string> $errors */
    private static function text(mixed $v, int $max, string $field, array &$errors): string
    {
        if (!is_string($v) || trim($v) === '') {
            $errors[$field] = 'Obrigatório.';
            return '';
        }
        $v = trim($v);
        if (mb_strlen($v) > $max) {
            $errors[$field] = "Máximo {$max} caracteres.";
        }
        return $v;
    }
}
