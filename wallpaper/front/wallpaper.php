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

    $error = Wallpaper::storeUpload($channel, $_FILES['image'] ?? []);
    if ($error !== '') {
        Session::addMessageAfterRedirect($error, false, ERROR);
    } else {
        Session::addMessageAfterRedirect(
            sprintf(__('Imagem atualizada no canal "%s".', 'wallpaper'), $channel),
            false,
            INFO
        );
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
        Config::setConfigurationValues(Wallpaper::CONFIG_CONTEXT, [
            'allowed_networks' => $networks,
            'trusted_proxies'  => $proxies,
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
        'url'       => Wallpaper::getPublicUrl($channel),
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
    'config'      => Wallpaper::getConfig(),
    'can_upload'  => Session::haveRight(Wallpaper::$rightname, UPDATE),
    'can_promote' => Session::haveRight(Wallpaper::$rightname, Wallpaper::RIGHT_PROMOTE),
    'csrf_token'  => Session::getNewCSRFToken(),
    'action'      => $redirect,
]);

Html::footer();
