<?php

/**
 * Ponto de entrada legado, mantido por compatibilidade:
 *   /plugins/wallpaper/front/image.php?c=producao
 *
 * A URL publica recomendada e a rota com extensao, atendida por
 * src/Controller/ImageController.php:
 *   /plugins/wallpaper/producao.jpg
 *
 * Ambos usam a mesma logica de cabecalhos e cache (src/ImageResponse.php).
 * Este arquivo continua util quando a rota do Controller nao esta disponivel
 * (GLPI 11 muito antigo) ou para diagnostico direto.
 *
 * Anonimo por necessidade: o download ocorre no contexto SYSTEM da maquina,
 * sem sessao GLPI. A liberacao no firewall esta em setup.php e cobre apenas
 * este arquivo.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Wallpaper\ImageResponse;

ImageResponse::send(
    ImageResponse::build((string) ($_GET['c'] ?? ''), $_SERVER)
);
