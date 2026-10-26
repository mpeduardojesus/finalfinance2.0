<?php
declare(strict_types=1);

namespace App;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

/** Router mínimo: padrões como /api/communications/{id}. */
final class Router
{
    /** @var list<array{string,string,callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            if ($method !== $request->method) {
                $allowed[] = $method;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request, $params);
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'Método não permitido.', ['allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'Recurso não encontrado.');
    }
}
