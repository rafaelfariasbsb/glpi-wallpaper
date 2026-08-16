<?php

/**
 * GLPI Wallpaper - hospeda imagens de wallpaper para consumo pelo Microsoft Intune.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Http\Firewall;
use GlpiPlugin\Wallpaper\Profile;
use GlpiPlugin\Wallpaper\Wallpaper;

define('PLUGIN_WALLPAPER_VERSION', '1.0.0');
define('PLUGIN_WALLPAPER_MIN_GLPI', '11.0.0');
define('PLUGIN_WALLPAPER_MAX_GLPI', '11.0.99');

function plugin_init_wallpaper(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['wallpaper'] = true;

    // O Intune baixa a imagem no contexto SYSTEM da maquina: sem sessao, sem cookie.
    // Sem esta linha o firewall do GLPI 11 responderia com um redirect para o login.
    // Liberamos apenas este endpoint; o painel continua exigindo autenticacao.
    Firewall::addPluginStrategyForLegacyScripts(
        'wallpaper',
        '#^/front/image\.php$#',
        Firewall::STRATEGY_NO_CHECK
    );

    Plugin::registerClass(Profile::class, ['addtabon' => \Profile::class]);

    if (Session::getLoginUserID() !== false && Wallpaper::canView()) {
        $PLUGIN_HOOKS['menu_toadd']['wallpaper'] = ['plugins' => Wallpaper::class];
    }
}

function plugin_version_wallpaper(): array
{
    return [
        'name'           => 'Wallpaper',
        'version'        => PLUGIN_WALLPAPER_VERSION,
        'author'         => 'Rafael Farias',
        'license'        => 'GPL-3.0-or-later',
        'homepage'       => 'https://github.com/rafaelfariasbsb/glpi-wallpaper',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_WALLPAPER_MIN_GLPI,
                'max' => PLUGIN_WALLPAPER_MAX_GLPI,
            ],
            'php' => ['min' => '8.2'],
        ],
    ];
}

function plugin_wallpaper_check_prerequisites(): bool
{
    if (!extension_loaded('gd') && !function_exists('getimagesize')) {
        echo 'A extensao GD do PHP e necessaria para validar as imagens enviadas.';
        return false;
    }
    return true;
}

function plugin_wallpaper_check_config($verbose = false): bool
{
    return true;
}
