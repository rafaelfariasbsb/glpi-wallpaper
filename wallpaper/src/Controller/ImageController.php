<?php

/**
 * Rota publica com extensao de imagem no final da URL:
 *
 *   /plugins/wallpaper/producao.jpg
 *   /plugins/wallpaper/piloto.png
 *
 * A extensao existe por exigencia pratica do Windows: o Personalization CSP
 * classifica o tipo do arquivo e reporta "Unknown file type" (DesktopImageStatus = 4)
 * quando nao reconhece o alvo. Uma URL terminada em "image.php?c=producao" nao
 * declara tipo algum, e a documentacao da Microsoft descreve o valor sempre como
 * uma URL "to a jpg, jpeg or png image".
 *
 * A extensao pedida NAO decide o que e servido: o Content-Type vem sempre do
 * arquivo real. Assim, trocar a imagem de PNG para JPEG nao obriga a editar a
 * politica no Intune.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Wallpaper\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Wallpaper\ImageResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ImageController extends AbstractController
{
    // O Intune baixa no contexto SYSTEM da maquina: sem sessao, sem cookie.
    // Sem esta excecao o firewall do GLPI 11 responderia com redirect ao login.
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    #[Route(
        '/{channel}.{extension}',
        name: 'plugin_wallpaper_image',
        requirements: [
            'channel'   => 'producao|piloto',
            'extension' => 'jpg|jpeg|png',
        ],
        methods: ['GET', 'HEAD']
    )]
    public function __invoke(Request $request, string $channel): Response
    {
        $result = ImageResponse::build($channel, $request->server->all());

        // Para HEAD o kernel remove o corpo em prepare(), preservando o
        // Content-Length que ja calculamos.
        $body = $result['send_body'] && $result['path'] !== null
            ? (string) file_get_contents($result['path'])
            : '';

        return new Response($body, $result['status'], $result['headers']);
    }
}
