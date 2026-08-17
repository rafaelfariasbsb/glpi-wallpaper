<?php

/**
 * Logica de entrega da imagem, compartilhada pelos dois pontos de entrada:
 * a rota bonita (Controller) e o script legado front/image.php.
 *
 * Fica isolada aqui para que os cabecalhos de seguranca e o tratamento de
 * cache condicional sejam identicos nos dois caminhos.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Wallpaper;

use Event;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;

class ImageResponse
{
    /**
     * Monta status e cabecalhos da resposta.
     *
     * @param array<string,mixed> $server tipicamente $_SERVER
     * @return array{status:int,headers:array<string,string>,path:?string,send_body:bool}
     */
    public static function build(string $channel, array $server): array
    {
        if (!Wallpaper::isValidChannel($channel)) {
            throw new NotFoundHttpException();
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        // O Front Door e o proprio Intune podem sondar com HEAD antes de baixar.
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return [
                'status'    => 405,
                'headers'   => ['Allow' => 'GET, HEAD', 'Cache-Control' => 'no-store'],
                'path'      => null,
                'send_body' => false,
            ];
        }

        self::assertNetworkAllowed($channel, $server);

        $data = Wallpaper::getChannel($channel);
        $path = Wallpaper::getFilePath($channel);

        // Canal ainda sem imagem, registro incompleto ou arquivo ausente no disco:
        // 404 limpo, nunca 200 com corpo vazio (o Intune trataria como invalido).
        if ($data === null || empty($data['mime']) || !is_file($path) || !is_readable($path)) {
            throw new NotFoundHttpException();
        }

        $mime = (string) $data['mime'];

        // Defesa em profundidade: o MIME ja foi validado no upload, mas se o
        // registro tiver sido adulterado nao ecoamos um Content-Type arbitrario.
        if (!isset(Wallpaper::ALLOWED_MIME[$mime])) {
            throw new NotFoundHttpException();
        }

        $size  = filesize($path);
        $mtime = filemtime($path);
        $etag  = Wallpaper::getEtag($channel, $data, $path);
        $ttl   = (int) Wallpaper::getConfig()['cache_ttl'];

        $headers = [
            // Content-Type exato vindo da allowlist, mais nosniff: nada deve
            // reinterpretar estes bytes como outra coisa.
            'Content-Type'           => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline; filename="wallpaper-' . $channel . '.'
                                        . Wallpaper::ALLOWED_MIME[$mime] . '"',
            // Cache na borda (Azure Front Door) e no cliente: endpoint anonimo
            // cacheado e endpoint que nao vira alvo barato de carga.
            'Cache-Control'          => $ttl > 0 ? 'public, max-age=' . $ttl : 'no-cache',
        ];

        if ($mtime !== false) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        }
        if ($etag !== '') {
            $headers['ETag'] = '"' . $etag . '"';
        }

        if (self::isNotModified($server, $etag, $mtime)) {
            // 304 nao leva corpo, Content-Type nem Content-Length.
            unset($headers['Content-Type'], $headers['Content-Disposition']);

            return ['status' => 304, 'headers' => $headers, 'path' => null, 'send_body' => false];
        }

        if ($size !== false) {
            $headers['Content-Length'] = (string) $size;
        }

        // HEAD recebe exatamente os mesmos cabecalhos, sem corpo.
        return [
            'status'    => 200,
            'headers'   => $headers,
            'path'      => $path,
            'send_body' => $method === 'GET',
        ];
    }

    /** @param array<string,mixed> $server */
    private static function assertNetworkAllowed(string $channel, array $server): void
    {
        $config = Wallpaper::getConfig();

        $previous = $_SERVER;
        $_SERVER  = $server;
        try {
            $client_ip = NetworkFilter::getClientIp(
                $config['trusted_proxies'],
                $config['client_ip_header']
            );
        } finally {
            $_SERVER = $previous;
        }

        if (NetworkFilter::isAllowed($client_ip, $config['allowed_networks'])) {
            return;
        }

        // O Intune nao reporta "fui bloqueado": a wallpaper simplesmente nao
        // aplica. Sem este log o diagnostico vira adivinhacao.
        Event::log(
            0,
            'system',
            3,
            'plugins',
            sprintf(
                'Wallpaper: acesso ao canal "%s" bloqueado para o IP %s pela lista de redes autorizadas.',
                $channel,
                $client_ip
            )
        );

        throw new AccessDeniedHttpException();
    }

    /**
     * Requisicao condicional. If-None-Match tem precedencia sobre
     * If-Modified-Since, conforme a RFC 9110.
     *
     * @param array<string,mixed> $server
     */
    private static function isNotModified(array $server, string $etag, $mtime): bool
    {
        $inm = trim((string) ($server['HTTP_IF_NONE_MATCH'] ?? ''));

        if ($inm !== '' && $etag !== '') {
            if ($inm === '*') {
                return true;
            }
            foreach (explode(',', $inm) as $candidate) {
                // Normaliza aspas e o prefixo W/ das validacoes fracas.
                $candidate = trim($candidate);
                $candidate = preg_replace('/^W\//i', '', $candidate) ?? $candidate;
                if (trim($candidate, '"') === $etag) {
                    return true;
                }
            }

            return false;
        }

        if ($mtime === false) {
            return false;
        }

        $ims = trim((string) ($server['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ims === '') {
            return false;
        }

        $since = strtotime($ims);

        return $since !== false && $mtime <= $since;
    }

    /**
     * Descarta os cabecalhos que o PHP ja tenha registrado antes da entrega.
     *
     * O GLPI abre a sessao antes de o endpoint ser alcancado, e com ela o PHP
     * registra um Set-Cookie e o Cache-Control do session.cache_limiter
     * ("no-store, no-cache, must-revalidate"). Em producao a resposta saia com
     * o cookie e DOIS Cache-Control, e o Azure Front Door desistia de cachear
     * (x-cache: CONFIG_NOCACHE) — cada maquina da frota baixava a imagem direto
     * do GLPI, que e exatamente o que o cache de borda existe para evitar neste
     * endpoint anonimo.
     *
     * Precisa ser chamado nos DOIS caminhos de entrega, por motivos diferentes:
     *
     * - no script legado, o header() de send() usa replace=true e sozinho ja
     *   resolveria o Cache-Control, mas nao remove o cookie;
     * - na rota do Controller quem escreve e o Symfony, e o Response::sendHeaders()
     *   emite tudo com replace=FALSE (so o Content-Type e substituido). La o
     *   cabecalho da sessao sobrevive ao lado do nosso, duplicado.
     *
     * Sendo anonimo, o endpoint nao tem sessao a manter nem cookie a enviar.
     */
    public static function discardPendingHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove();
    }

    /**
     * Envia a resposta com header()/readfile() e encerra.
     *
     * Qualquer warning ja emitido por PHP corromperia os bytes da imagem, entao
     * descartamos todo buffer pendente antes de escrever.
     *
     * @param array{status:int,headers:array<string,string>,path:?string,send_body:bool} $response
     */
    public static function send(array $response): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ini_set('display_errors', '0');

        self::discardPendingHeaders();

        $status  = $response['status'];
        $headers = $response['headers'];

        // O terceiro argumento de header() define o status de forma confiavel;
        // http_response_code() e problematico no GLPI 11. Aproveitamos o
        // primeiro cabecalho real para carrega-lo.
        if ($headers === []) {
            header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $status, true, $status);
        }

        $first = true;
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true, $first ? $status : 0);
            $first = false;
        }

        if ($response['send_body'] && $response['path'] !== null) {
            readfile($response['path']);
        }

        exit;
    }
}
