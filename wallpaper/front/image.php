<?php

/**
 * Endpoint publico consumido pelo Microsoft Intune.
 *
 * URLs fixas, cadastradas uma unica vez na politica:
 *   /plugins/wallpaper/front/image.php?c=producao
 *   /plugins/wallpaper/front/image.php?c=piloto
 *
 * Anonimo por necessidade: o download ocorre no contexto SYSTEM da maquina,
 * sem sessao GLPI. A liberacao no firewall esta em setup.php e cobre apenas
 * este arquivo.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Wallpaper\NetworkFilter;
use GlpiPlugin\Wallpaper\Wallpaper;

$channel = (string) ($_GET['c'] ?? '');

if (!Wallpaper::isValidChannel($channel)) {
    throw new NotFoundHttpException();
}

$config    = Wallpaper::getConfig();
$client_ip = NetworkFilter::getClientIp($config['trusted_proxies']);

if (!NetworkFilter::isAllowed($client_ip, $config['allowed_networks'])) {
    // O Intune nao reporta "fui bloqueado": a wallpaper simplesmente nao aplica.
    // Sem este log o diagnostico vira adivinhacao.
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

$data = Wallpaper::getChannel($channel);
if ($data === null || empty($data['mime'])) {
    throw new NotFoundHttpException();
}

$path = Wallpaper::getFilePath($channel);
if (!is_file($path)) {
    throw new NotFoundHttpException();
}

$extension = Wallpaper::ALLOWED_MIME[$data['mime']] ?? 'jpg';

// getFileAsResponse cuida de ETag / Last-Modified / 304, evitando que o Intune
// baixe a imagem inteira a cada sincronizacao.
$response = Toolbox::getFileAsResponse(
    $path,
    'wallpaper-' . $channel . '.' . $extension,
    $data['mime'],
    true
);
$response->send();
