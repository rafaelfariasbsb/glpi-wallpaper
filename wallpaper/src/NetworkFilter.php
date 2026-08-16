<?php

/**
 * Restricao opcional de acesso ao endpoint publico por faixa de IP.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Wallpaper;

class NetworkFilter
{
    /**
     * IP real do cliente.
     *
     * Cabecalhos de IP sao forjaveis por qualquer cliente. So os aceitamos quando
     * a conexao vem de um proxy que o administrador cadastrou explicitamente;
     * caso contrario o bloqueio por IP seria trivial de contornar.
     *
     * Atras de um CDN como o Azure Front Door, REMOTE_ADDR e sempre o IP da borda,
     * nunca o da maquina: sem os ranges do CDN em $trusted_proxies o filtro
     * avaliaria o CDN e nao o device.
     *
     * @param string $header cabecalho a consultar; ver Wallpaper::CLIENT_IP_HEADERS
     */
    public static function getClientIp(string $trusted_proxies, string $header = 'X-Forwarded-For'): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $proxies = self::parseList($trusted_proxies);
        if ($proxies === [] || !self::matches($remote, $proxies)) {
            return $remote;
        }

        $key       = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        $forwarded = (string) ($_SERVER[$key] ?? '');
        if ($forwarded === '') {
            return $remote;
        }

        // O cliente original e o primeiro da cadeia (o XFF vem concatenado;
        // o X-Azure-ClientIP traz um endereco unico, e o explode e inofensivo).
        $first = trim(explode(',', $forwarded)[0]);

        return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : $remote;
    }

    /**
     * Lista vazia libera qualquer origem: e o padrao, para nao quebrar maquinas
     * fora da rede corporativa em quem nunca configurou nada.
     */
    public static function isAllowed(string $ip, string $allowed_networks): bool
    {
        $networks = self::parseList($allowed_networks);

        return $networks === [] || self::matches($ip, $networks);
    }

    /** @return string[] */
    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];

        return array_values(array_filter($parts, static fn($p) => $p !== ''));
    }

    /**
     * Valida a sintaxe de cada entrada da lista.
     *
     * @param string[] $entries
     * @return string[] entradas invalidas
     */
    public static function findInvalid(array $entries): array
    {
        $invalid = [];
        foreach ($entries as $entry) {
            if (self::parseCidr($entry) === null) {
                $invalid[] = $entry;
            }
        }
        return $invalid;
    }

    /** @param string[] $networks */
    public static function matches(string $ip, array $networks): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($networks as $network) {
            $cidr = self::parseCidr($network);
            if ($cidr === null) {
                continue;
            }
            [$subnet, $bits] = $cidr;

            // Comparar IPv4 com IPv6 nao faz sentido.
            if (strlen($subnet) !== strlen($packed)) {
                continue;
            }
            if (self::samePrefix($packed, $subnet, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:string,1:int}|null [endereco empacotado, tamanho do prefixo]
     */
    private static function parseCidr(string $network): ?array
    {
        $network = trim($network);
        if ($network === '') {
            return null;
        }

        if (str_contains($network, '/')) {
            [$addr, $prefix] = explode('/', $network, 2);
            if ($prefix === '' || !ctype_digit($prefix)) {
                return null;
            }
            $bits = (int) $prefix;
        } else {
            // IP solto: trata como host unico.
            $addr = $network;
            $bits = null;
        }

        $packed = @inet_pton(trim($addr));
        if ($packed === false) {
            return null;
        }

        $max = strlen($packed) * 8;
        $bits ??= $max;

        if ($bits < 0 || $bits > $max) {
            return null;
        }

        return [$packed, $bits];
    }

    private static function samePrefix(string $a, string $b, int $bits): bool
    {
        $whole_bytes = intdiv($bits, 8);
        $rest_bits   = $bits % 8;

        if ($whole_bytes > 0 && substr($a, 0, $whole_bytes) !== substr($b, 0, $whole_bytes)) {
            return false;
        }

        if ($rest_bits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $rest_bits) & 0xFF;

        return (ord($a[$whole_bytes]) & $mask) === (ord($b[$whole_bytes]) & $mask);
    }
}
