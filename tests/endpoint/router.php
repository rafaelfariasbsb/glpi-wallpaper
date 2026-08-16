<?php

/**
 * Router do servidor embutido do PHP para o teste de endpoint.
 *
 * Reproduz o que o GLPI faz por nos em producao: rotear a URL e converter as
 * excecoes HTTP em respostas 404/403.
 *
 * @license GPL-3.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Wallpaper\ImageResponse;

$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

try {
    // Rota bonita: /plugins/wallpaper/<canal>.<ext> (equivale ao ImageController).
    if (preg_match('#^/plugins/wallpaper/([a-z]+)\.(jpe?g|png)$#', $path, $m)) {
        ImageResponse::send(ImageResponse::build($m[1], $_SERVER));
    }

    // Rota legada: /plugins/wallpaper/front/image.php?c=<canal>
    if ($path === '/plugins/wallpaper/front/image.php') {
        ImageResponse::send(ImageResponse::build((string) ($_GET['c'] ?? ''), $_SERVER));
    }

    http_response_code(404);
    echo "sem rota\n";
} catch (NotFoundHttpException) {
    http_response_code(404);
    echo "404\n";
} catch (AccessDeniedHttpException) {
    http_response_code(403);
    echo "403\n";
}
