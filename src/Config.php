<?php
declare(strict_types=1);

namespace App;

/** Configuração lida apenas de variáveis de ambiente (sem credenciais no código). */
final class Config
{
    public static function env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }

    public static function debug(): bool
    {
        return self::env('APP_DEBUG', '0') === '1';
    }

    public static function oracleConfigured(): bool
    {
        foreach (['ORACLE_HOST', 'ORACLE_SERVICE', 'ORACLE_USER', 'ORACLE_PASSWORD'] as $k) {
            if (self::env($k) === '') {
                return false;
            }
        }
        return true;
    }

    /** @return array{host:string,port:string,service:string,user:string,password:string,charset:string} */
    public static function oracle(): array
    {
        return [
            'host' => self::env('ORACLE_HOST'),
            'port' => self::env('ORACLE_PORT', '1521'),
            'service' => self::env('ORACLE_SERVICE'),
            'user' => self::env('ORACLE_USER'),
            'password' => self::env('ORACLE_PASSWORD'),
            'charset' => self::env('ORACLE_CHARSET', 'AL32UTF8'),
        ];
    }
}
