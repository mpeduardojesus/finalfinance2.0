<?php
declare(strict_types=1);

namespace App\Treasury;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Router;

/** Rotas /api/treasury/* (protegidas por HTTP Basic em index.php). */
final class TreasuryApi
{
    private readonly TreasuryService $svc;

    public function __construct(string $root)
    {
        $this->svc = new TreasuryService(new Storage($root));
    }

    public function routes(): Router
    {
        $r = new Router();
        $s = $this->svc;

        $r->add('GET', '/api/treasury/state', fn (): Response => new Response(200, $s->state()));
        $r->add('GET', '/api/treasury/plan', fn (): Response => new Response(200, $s->plan()));
        $r->add('GET', '/api/treasury/plan/detail', fn (Request $q): Response => new Response(200, $s->planDetail(
            (string) ($q->query['bank'] ?? ''),
            (int) ($q->query['period'] ?? -1),
            ($q->query['rec'] ?? '1') !== '0',
            (int) ($q->query['page'] ?? 0),
            ($q->query['fat'] ?? '0') === '1',
        )));

        $r->add('GET', '/api/treasury/dashboard', fn (): Response => new Response(200, $s->dashboard()));
        $r->add('GET', '/api/treasury/suggestions', fn (): Response => new Response(200, $s->suggestions()));
        // Banco sugerido para pagamentos: ordem de prioridade e aceitação (converte em atribuições manuais)
        $r->add('PUT', '/api/treasury/priority', fn (Request $q): Response => new Response(200, ['prio' => $s->setPriority((array) ($q->json()['order'] ?? []))]));
        $r->add('POST', '/api/treasury/suggestions/accept', fn (Request $q): Response => new Response(200, $s->acceptSuggestion((string) ($q->json()['mode'] ?? ''))));

        $r->add('GET', '/api/treasury/open-items', fn (Request $q): Response => new Response(200, $s->openItems(
            (string) ($q->query['tipo'] ?? ''),
            (string) ($q->query['bank'] ?? ''),
            (string) ($q->query['prazo'] ?? ''),
            (string) ($q->query['q'] ?? ''),
            (int) ($q->query['page'] ?? 0),
        )));

        $r->add('GET', '/api/treasury/settings', fn (): Response => new Response(200, $s->state()['settings']));
        $r->add('PUT', '/api/treasury/settings', fn (Request $q): Response => new Response(200, $s->updateSettings($q->json())));

        $r->add('GET', '/api/treasury/banks', fn (): Response => new Response(200, $s->state()['banks']));
        $r->add('POST', '/api/treasury/banks', function (Request $q) use ($s): Response {
            $b = $s->createBank($q->json());
            return new Response(201, $b, ['Location' => '/api/treasury/banks/' . $b['id']]);
        });
        $r->add('PUT', '/api/treasury/banks/{id}', fn (Request $q, array $p): Response => new Response(200, $s->updateBank($this->id($p), $q->json())));
        $r->add('DELETE', '/api/treasury/banks/{id}', function (Request $q, array $p) use ($s): Response {
            $s->deleteBank($this->id($p));
            return new Response(204);
        });

        $r->add('GET', '/api/treasury/manual-entries', fn (): Response => new Response(200, $s->manualEntries()));
        $r->add('POST', '/api/treasury/manual-entries', function (Request $q) use ($s): Response {
            $e = $s->createManual($q->json());
            return new Response(201, $e, ['Location' => '/api/treasury/manual-entries/' . $e['id']]);
        });
        $r->add('PUT', '/api/treasury/manual-entries/{id}', fn (Request $q, array $p): Response => new Response(200, $s->updateManual($this->id($p), $q->json())));
        $r->add('DELETE', '/api/treasury/manual-entries/{id}', function (Request $q, array $p) use ($s): Response {
            $s->deleteManual($this->id($p));
            return new Response(204);
        });

        // Movimentos recorrentes
        $r->add('GET', '/api/treasury/recurring', fn (): Response => new Response(200, $s->recurring()));
        $r->add('POST', '/api/treasury/recurring', function (Request $q) use ($s): Response {
            $e = $s->createRecurring($q->json());
            return new Response(201, $e, ['Location' => '/api/treasury/recurring/' . $e['id']]);
        });
        $r->add('PUT', '/api/treasury/recurring/{id}', fn (Request $q, array $p): Response => new Response(200, $s->updateRecurring($this->id($p), $q->json())));
        $r->add('DELETE', '/api/treasury/recurring/{id}', function (Request $q, array $p) use ($s): Response {
            $s->deleteRecurring($this->id($p));
            return new Response(204);
        });

        // Entidades nos dois lados (cliente e fornecedor): tratamento por entidade
        $r->add('PUT', '/api/treasury/dual/{id}', function (Request $q, array $p) use ($s): Response {
            $s->setDualClass($this->id($p), (string) ($q->json()['classe'] ?? ''));
            return new Response(204);
        });
        $r->add('DELETE', '/api/treasury/dual', function () use ($s): Response {
            $s->resetDualClasses();
            return new Response(204);
        });

        // Alterações a documentos do Sage (o ficheiro Sage nunca é modificado)
        $r->add('PUT', '/api/treasury/overrides/{id}', fn (Request $q, array $p): Response => new Response(200, $s->setOverride($this->id($p), $q->json())));
        $r->add('DELETE', '/api/treasury/overrides/{id}', function (Request $q, array $p) use ($s): Response {
            $s->clearOverride($this->id($p));
            return new Response(204);
        });
        $r->add('DELETE', '/api/treasury/overrides', fn (): Response => new Response(200, ['removed' => $s->clearOverride(null)]));

        $r->add('GET', '/api/treasury/entities', fn (Request $q): Response => new Response(200, $s->entitiesPage(
            (string) ($q->query['side'] ?? 'P'),
            (string) ($q->query['q'] ?? ''),
            ($q->query['only'] ?? '0') === '1',
            (int) ($q->query['page'] ?? 0),
        )));
        $r->add('PUT', '/api/treasury/entities/{key}', function (Request $q, array $p) use ($s): Response {
            $in = $q->json();
            $s->setPartyBank($p['key'], (string) ($in['banco'] ?? ''));
            return new Response(204);
        });
        $r->add('POST', '/api/treasury/entities/bulk', function (Request $q) use ($s): Response {
            $in = $q->json();
            return new Response(200, $s->bulkPartyBank(
                (string) ($in['side'] ?? 'P'), (string) ($in['q'] ?? ''), ($in['only'] ?? false) === true,
                (string) ($in['mode'] ?? ''), (string) ($in['banco'] ?? ''),
            ));
        });

        $r->add('GET', '/api/treasury/export', fn (Request $q): Response => new Response(200, ['text' => $s->export((string) ($q->query['kind'] ?? 'documents'))]));
        $r->add('POST', '/api/treasury/import', function (Request $q) use ($s): Response {
            $in = $q->json();
            return new Response(200, $s->import((string) ($in['kind'] ?? ''), (string) ($in['text'] ?? ''), ($in['replace'] ?? false) === true));
        });

        // Carregar/substituir o ficheiro Sage X3 (multipart/form-data, campo "file")
        $r->add('POST', '/api/treasury/sage-file', function () use ($s): Response {
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null)) {
                $code = is_array($f) ? (int) ($f['error'] ?? 4) : 4;
                throw new HttpException(in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400,
                    in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Ficheiro demasiado grande (ver upload_max_filesize).' : 'Nenhum ficheiro recebido (campo "file").');
            }
            return new Response(200, $s->uploadSage($f['tmp_name'], (string) ($f['name'] ?? '')));
        });

        // Relação cliente → banco (clientes_bancos.xlsx, multipart/form-data, campo "file") e reposição
        $r->add('POST', '/api/treasury/clientes-bancos', function () use ($s): Response {
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null)) {
                $code = is_array($f) ? (int) ($f['error'] ?? 4) : 4;
                throw new HttpException(in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400,
                    in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Ficheiro demasiado grande (ver upload_max_filesize).' : 'Nenhum ficheiro recebido (campo "file").');
            }
            return new Response(200, $s->uploadClientes($f['tmp_name'], (string) ($f['name'] ?? '')));
        });
        $r->add('POST', '/api/treasury/clientes-bancos/reset', fn (): Response => new Response(200, $s->resetClientes()));
        $r->add('POST', '/api/treasury/reset', fn (): Response => new Response(200, $s->reset()));

        return $r;
    }

    /** @param array<string,string> $p */
    private function id(array $p): string
    {
        $id = (string) ($p['id'] ?? '');
        if (preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $id) !== 1) {
            throw new HttpException(400, 'Identificador inválido.');
        }
        return $id;
    }
}
