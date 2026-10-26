<?php
declare(strict_types=1);

// Front controller: /api/* → API JSON; restantes caminhos → página inicial.
require __DIR__ . '/../src/autoload.php';

use App\Api;
use App\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\StoreFactory;
use App\Treasury\Auth as TreasuryAuth;
use App\Treasury\TreasuryApi;

$root = dirname(__DIR__);
$request = Request::fromGlobals();

if ($request->path === '/' || $request->path === '/index.php') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/views/home.html');
    exit;
}

// Plano de tesouraria: página e API protegidas por HTTP Basic (TREASURY_USER / TREASURY_PASSWORD); dados confidenciais.
$isTreasuryApi = $request->path === '/api/treasury' || str_starts_with($request->path, '/api/treasury/');
if ($isTreasuryApi || $request->path === '/treasury') {
    TreasuryAuth::sendSecurityHeaders();
    $denied = TreasuryAuth::check($request);
    if ($denied !== null) {
        $denied->send();
        exit;
    }
    if ($request->path === '/treasury') {
        header('Content-Type: text/html; charset=utf-8');
        // CSP: dados só para a própria origem; scripts/estilos estáticos apenas do próprio site e do cdnjs
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
        readfile($root . '/views/treasury.html');
        exit;
    }
    try {
        $response = (new TreasuryApi($root))->routes()->dispatch($request);
    } catch (HttpException $e) {
        $headers = isset($e->details['allow']) ? ['Allow' => $e->details['allow']] : [];
        $body = ['error' => ['status' => $e->status, 'message' => $e->getMessage()]];
        if ($e->details !== [] && !isset($e->details['allow'])) {
            $body['error']['details'] = $e->details;
        }
        $response = new Response($e->status, $body, $headers);
    } catch (Throwable $e) {
        // Nunca registar conteúdo (mensagens/argumentos podem conter dados): só classe e local.
        error_log('Treasury erro ' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
        $response = new Response(500, ['error' => ['status' => 500, 'message' => 'Erro interno.']]);
    }
    $response->send();
    exit;
}

if (!str_starts_with($request->path, '/api/') && $request->path !== '/api') {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>404</title><p>Página não encontrada. <a href="/">Voltar</a></p>';
    exit;
}

try {
    $api = new Api(StoreFactory::create($root . '/data'), $root . '/data');
    $response = $api->routes()->dispatch($request);
} catch (HttpException $e) {
    $headers = isset($e->details['allow']) ? ['Allow' => $e->details['allow']] : [];
    $body = ['error' => ['status' => $e->status, 'message' => $e->getMessage()]];
    if ($e->details !== [] && !isset($e->details['allow'])) {
        $body['error']['details'] = $e->details;
    }
    $response = new Response($e->status, $body, $headers);
} catch (Throwable $e) {
    error_log((string) $e);
    $response = new Response(500, ['error' => [
        'status' => 500,
        'message' => 'Erro interno.',
    ] + (Config::debug() ? ['debug' => $e->getMessage()] : [])]);
}

$response->send();
