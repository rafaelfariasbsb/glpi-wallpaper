<?php

/**
 * Painel de administracao do plugin. Protegido pelos perfis do GLPI.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Wallpaper\NetworkFilter;
use GlpiPlugin\Wallpaper\Wallpaper;

Session::checkRight(Wallpaper::$rightname, READ);

$redirect = $CFG_GLPI['root_doc'] . '/plugins/wallpaper/front/wallpaper.php';

if (isset($_POST['upload'])) {
    $channel = (string) ($_POST['channel'] ?? '');

    // Subir direto em producao atinge a frota inteira: exige o mesmo direito
    // de quem pode promover.
    $needed = $channel === Wallpaper::CHANNEL_PRODUCTION ? Wallpaper::RIGHT_PROMOTE : UPDATE;
    Session::checkRight(Wallpaper::$rightname, $needed);

    $previous_ext = Wallpaper::getExtension($channel);
    $had_image    = !empty((Wallpaper::getChannel($channel) ?? [])['mime']);

    $error = Wallpaper::storeUpload($channel, $_FILES['image'] ?? []);
    if ($error !== '') {
        Session::addMessageAfterRedirect($error, false, ERROR);
    } else {
        Session::addMessageAfterRedirect(
            sprintf(__('Imagem atualizada no canal "%s".', 'wallpaper'), $channel),
            false,
            INFO
        );

        // A URL publica termina na extensao do formato atual. Trocar de PNG para
        // JPEG (ou vice-versa) muda a URL recomendada, e a politica do Intune
        // aponta para a antiga. A rota antiga continua respondendo, mas o
        // administrador precisa saber da divergencia.
        $new_ext = Wallpaper::getExtension($channel);
        if ($had_image && $new_ext !== $previous_ext) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __(
                        'O formato mudou de %1$s para %2$s. A URL recomendada passou a terminar '
                        . 'em .%2$s — a politica do Intune com a URL antiga continua funcionando, '
                        . 'mas atualize-a para manter a extensao coerente com o conteudo.',
                        'wallpaper'
                    ),
                    $previous_ext,
                    $new_ext
                ),
                false,
                WARNING
            );
        }
    }
    Html::redirect($redirect);
}

if (isset($_POST['promote'])) {
    Session::checkRight(Wallpaper::$rightname, Wallpaper::RIGHT_PROMOTE);

    $error = Wallpaper::promote();
    if ($error !== '') {
        Session::addMessageAfterRedirect($error, false, ERROR);
    } else {
        Session::addMessageAfterRedirect(
            __('Imagem do piloto promovida para producao.', 'wallpaper'),
            false,
            INFO
        );
    }
    Html::redirect($redirect);
}

if (isset($_POST['save_config'])) {
    Session::checkRight(Wallpaper::$rightname, UPDATE);

    $networks = (string) ($_POST['allowed_networks'] ?? '');
    $proxies  = (string) ($_POST['trusted_proxies'] ?? '');
    $ttl      = max(0, (int) ($_POST['cache_ttl'] ?? Wallpaper::DEFAULT_CACHE_TTL));

    $header = (string) ($_POST['client_ip_header'] ?? 'X-Forwarded-For');
    if (!in_array($header, Wallpaper::CLIENT_IP_HEADERS, true)) {
        $header = 'X-Forwarded-For';
    }

    $invalid = array_merge(
        NetworkFilter::findInvalid(NetworkFilter::parseList($networks)),
        NetworkFilter::findInvalid(NetworkFilter::parseList($proxies))
    );

    if ($invalid !== []) {
        Session::addMessageAfterRedirect(
            sprintf(__('Entradas invalidas: %s', 'wallpaper'), implode(', ', $invalid)),
            false,
            ERROR
        );
    } else {
        // Restringir por IP sem declarar os proxies da borda bloquearia todo mundo:
        // atras de CDN o REMOTE_ADDR e sempre o da borda, nunca o do device.
        if ($networks !== '' && $proxies === '') {
            Session::addMessageAfterRedirect(
                __(
                    'Atencao: ha redes autorizadas sem nenhum proxy confiavel declarado. '
                    . 'Se o GLPI estiver atras de CDN ou proxy reverso, o filtro avaliara o IP '
                    . 'da borda e nao o da maquina.',
                    'wallpaper'
                ),
                false,
                WARNING
            );
        }

        Config::setConfigurationValues(Wallpaper::CONFIG_CONTEXT, [
            'allowed_networks' => $networks,
            'trusted_proxies'  => $proxies,
            'cache_ttl'        => (string) $ttl,
            'client_ip_header' => $header,
        ]);
        Session::addMessageAfterRedirect(__('Configuracao salva.', 'wallpaper'), false, INFO);
    }
    Html::redirect($redirect);
}

Html::header(
    Wallpaper::getTypeName(),
    $_SERVER['PHP_SELF'],
    'plugins',
    Wallpaper::class
);

$channels = [];
foreach (Wallpaper::CHANNELS as $channel) {
    $data = Wallpaper::getChannel($channel) ?? [];
    $channels[$channel] = [
        'name'      => $channel,
        'url'        => Wallpaper::getPublicUrl($channel),
        'legacy_url' => Wallpaper::getLegacyUrl($channel),
        'has_image' => !empty($data['mime']),
        'filename'  => $data['filename'] ?? '',
        'mime'      => $data['mime'] ?? '',
        'filesize'  => (int) ($data['filesize'] ?? 0),
        'width'     => (int) ($data['width'] ?? 0),
        'height'    => (int) ($data['height'] ?? 0),
        'date_mod'  => $data['date_mod'] ?? null,
    ];
}

TemplateRenderer::getInstance()->display('@wallpaper/panel.html.twig', [
    'channels'    => $channels,
    'config'            => Wallpaper::getConfig(),
    'client_ip_headers' => Wallpaper::CLIENT_IP_HEADERS,
    'can_upload'  => Session::haveRight(Wallpaper::$rightname, UPDATE),
    'can_promote' => Session::haveRight(Wallpaper::$rightname, Wallpaper::RIGHT_PROMOTE),
    'csrf_token'  => Session::getNewCSRFToken(),
    'action'      => $redirect,
]);

Html::footer();
