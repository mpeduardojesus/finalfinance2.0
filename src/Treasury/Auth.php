<?php
declare(strict_types=1);

namespace App\Treasury;

use App\Config;
use App\Http\Request;
use App\Http\Response;

/**
 * HTTP Basic para /treasury e /api/treasury/*.
 * Credenciais só das variáveis TREASURY_USER / TREASURY_PASSWORD; se não estiverem definidas, o acesso é recusado (503).
 */
final class Auth
{
    /** @return Response|null  null = autorizado */
    public static function check(Request $request): ?Response
    {
        $user = Config::env('TREASURY_USER');
        $pass = Config::env('TREASURY_PASSWORD');
        if ($user === '' || $pass === '') {
            return new Response(503, ['error' => [
                'status' => 503,
                'message' => 'Acesso ao Plano de tesouraria desativado: defina TREASURY_USER e TREASURY_PASSWORD no ambiente.',
            ]]);
        }

        [$u, $p] = self::credentials();
        // Comparação em tempo constante (hash para igualar comprimentos); avalia sempre as duas.
        $okU = hash_equals(hash('sha256', $user), hash('sha256', $u));
        $okP = hash_equals(hash('sha256', $pass), hash('sha256', $p));
        if (!($okU && $okP)) {
            usleep(300000); // atrasa tentativas por força bruta
            return new Response(401, ['error' => ['status' => 401, 'message' => 'Autenticação necessária.']], [
                'WWW-Authenticate' => 'Basic realm="Plano de tesouraria", charset="UTF-8"',
            ]);
        }

        // Pedidos que alteram dados: exigir cabeçalho personalizado (força pré-verificação CORS → bloqueia CSRF entre sites)
        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
            if ($xrw !== 'XMLHttpRequest') {
                return new Response(403, ['error' => ['status' => 403, 'message' => 'Pedido recusado (cabeçalho X-Requested-With em falta).']]);
            }
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if ($origin !== '') {
                $host = parse_url($origin, PHP_URL_HOST) . (($port = parse_url($origin, PHP_URL_PORT)) ? ':' . $port : '');
                if (!hash_equals((string) ($_SERVER['HTTP_HOST'] ?? ''), $host)) {
                    return new Response(403, ['error' => ['status' => 403, 'message' => 'Origem não permitida.']]);
                }
            }
        }
        return null;
    }

    /** @return array{0:string,1:string} */
    private static function credentials(): array
    {
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return [(string) $_SERVER['PHP_AUTH_USER'], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
        }
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($h) && stripos($h, 'basic ') === 0) {
            $dec = base64_decode(substr($h, 6), true);
            if ($dec !== false && str_contains($dec, ':')) {
                [$u, $p] = explode(':', $dec, 2);
                return [$u, $p];
            }
        }
        return ['', ''];
    }

    /** Cabeçalhos de segurança/privacidade para as respostas da secção. */
    public static function sendSecurityHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
    }
}
