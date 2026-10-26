<?php
declare(strict_types=1);

namespace App;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\DataStore;

/** Registo das rotas /api. */
final class Api
{
    public function __construct(private readonly DataStore $store, private readonly string $dataDir)
    {
    }

    public function routes(): Router
    {
        $r = new Router();

        $r->add('GET', '/api/health', function (): Response {
            $db = $this->store->ping();
            return new Response($db['ok'] ? 200 : 503, [
                'status' => $db['ok'] ? 'ok' : 'degraded',
                'backend' => $this->store->name(),
                'detail' => $db['detail'],
                'php' => PHP_VERSION,
                'time' => gmdate('c'),
            ]);
        });

        $r->add('GET', '/api/communications', fn (): Response => new Response(200, $this->store->communications()));

        $r->add('GET', '/api/communications/{id}', function (Request $req, array $p): Response {
            $row = $this->store->communication($this->id($p));
            return $row === null ? throw new HttpException(404, 'Comunicação não encontrada.') : new Response(200, $row);
        });

        $r->add('POST', '/api/communications', function (Request $req): Response {
            $row = $this->store->createCommunication(Validator::communication($req->json()));
            return new Response(201, $row, ['Location' => '/api/communications/' . $row['id']]);
        });

        $r->add('PUT', '/api/communications/{id}', function (Request $req, array $p): Response {
            $id = $this->id($p);
            $row = $this->store->updateCommunication($id, Validator::communication($req->json()));
            return $row === null ? throw new HttpException(404, 'Comunicação não encontrada.') : new Response(200, $row);
        });

        $r->add('DELETE', '/api/communications/{id}', function (Request $req, array $p): Response {
            return $this->store->deleteCommunication($this->id($p))
                ? new Response(204)
                : throw new HttpException(404, 'Comunicação não encontrada.');
        });

        $r->add('GET', '/api/facts', fn (): Response => new Response(200, $this->store->facts()));

        $r->add('GET', '/api/menu', function (): Response {
            $json = file_get_contents($this->dataDir . '/menu.json');
            return new Response(200, json_decode($json === false ? '[]' : $json, true) ?? []);
        });

        return $r;
    }

    /** @param array<string,string> $p */
    private function id(array $p): int
    {
        if (!ctype_digit($p['id'] ?? '') || strlen($p['id']) > 12) {
            throw new HttpException(400, 'Identificador inválido.');
        }
        return (int) $p['id'];
    }
}
