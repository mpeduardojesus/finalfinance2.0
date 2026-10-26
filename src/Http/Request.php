<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @param array<string,mixed> $query */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private readonly string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
        $path = '/' . trim($path, '/');
        return new self($method, $path, $_GET, (string) file_get_contents('php://input'));
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if (trim($this->rawBody) === '') {
            throw new HttpException(400, 'Corpo do pedido vazio. Envie JSON.');
        }
        try {
            $data = json_decode($this->rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'JSON inválido.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new HttpException(400, 'O corpo deve ser um objeto JSON.');
        }
        return $data;
    }
}
