<?php

/**
 * GLPI Wallpaper - hospeda imagens de wallpaper para consumo pelo Microsoft Intune.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Http\Firewall;
use GlpiPlugin\Wallpaper\Profile;
use GlpiPlugin\Wallpaper\Wallpaper;

define('PLUGIN_WALLPAPER_VERSION', '1.1.2');
define('PLUGIN_WALLPAPER_MIN_GLPI', '11.0.0');
define('PLUGIN_WALLPAPER_MAX_GLPI', '11.0.99');

function plugin_init_wallpaper(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['wallpaper'] = true;

    // Botao de chave inglesa no cartao do plugin, em Configurar > Plug-ins.
    // Ao contrario do menu lateral, este atalho nao depende do cache de sessao:
    // aparece assim que o plugin e ativado.
    $PLUGIN_HOOKS['config_page']['wallpaper'] = 'front/wallpaper.php';

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
        // Setor "config" (menu Configurar). O setor "plugins" nem sempre e
        // renderizado na barra lateral do GLPI 11, e a entrada ficava invisivel.
        // Lembre que o menu vive em $_SESSION['glpimenu'] e so e remontado no
        // login: apos instalar, e preciso sair e entrar novamente.
        $PLUGIN_HOOKS['menu_toadd']['wallpaper'] = ['config' => Wallpaper::class];
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
