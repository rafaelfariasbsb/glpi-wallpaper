<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Wallpaper;

use CommonDBTM;
use Config;
use Session;

class Wallpaper extends CommonDBTM
{
    public static $rightname = 'plugin_wallpaper';

    /** Direito extra: promover a imagem do piloto para producao. */
    public const RIGHT_PROMOTE = 1024;

    public const CHANNEL_PRODUCTION = 'producao';
    public const CHANNEL_PILOT      = 'piloto';

    /** Canais fixos. As URLs derivam daqui e nunca mudam. */
    public const CHANNELS = [self::CHANNEL_PRODUCTION, self::CHANNEL_PILOT];

    public const CONFIG_CONTEXT = 'plugin:Wallpaper';

    public const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
    ];

    public static function getTypeName($nb = 0)
    {
        return __('Wallpaper', 'wallpaper');
    }

    public static function getIcon()
    {
        return 'ti ti-photo';
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_wallpaper_channels';
    }

    public function getRights($interface = 'central')
    {
        return [
            READ                 => __('Ver o painel', 'wallpaper'),
            UPDATE               => __('Enviar imagem para o canal piloto', 'wallpaper'),
            self::RIGHT_PROMOTE  => __('Promover piloto para producao', 'wallpaper'),
        ];
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(),
            'page'  => '/plugins/wallpaper/front/wallpaper.php',
            'icon'  => self::getIcon(),
        ];
    }

    /** Diretorio de armazenamento. Fora do docroot: so o endpoint le de la. */
    public static function getStorageDir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/wallpaper';
    }

    /**
     * Caminho do arquivo de um canal. O nome deriva do canal, que e sempre
     * validado contra a lista fixa: nao ha como injetar caminho aqui.
     */
    public static function getFilePath(string $channel): string
    {
        if (!self::isValidChannel($channel)) {
            throw new \InvalidArgumentException('Canal invalido');
        }
        return self::getStorageDir() . '/' . $channel . '.bin';
    }

    public static function isValidChannel(string $channel): bool
    {
        return in_array($channel, self::CHANNELS, true);
    }

    /** URL publica e fixa do canal, cadastrada uma unica vez no Intune. */
    public static function getPublicUrl(string $channel): string
    {
        global $CFG_GLPI;

        return ($CFG_GLPI['url_base'] ?? $CFG_GLPI['root_doc'])
            . '/plugins/wallpaper/front/image.php?c=' . $channel;
    }

    /** @return array<string,mixed>|null */
    public static function getChannel(string $channel): ?array
    {
        global $DB;

        if (!self::isValidChannel($channel)) {
            return null;
        }

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['channel' => $channel],
            'LIMIT' => 1,
        ])->current();

        return $row ?: null;
    }

    /** @return array<string,mixed> */
    public static function getConfig(): array
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT);

        return [
            'allowed_networks' => $values['allowed_networks'] ?? '',
            'trusted_proxies'  => $values['trusted_proxies'] ?? '',
        ];
    }

    /**
     * Grava a imagem enviada no canal informado.
     *
     * @param array{tmp_name:string,size:int,error:int,name:string} $file entrada de $_FILES
     * @return string mensagem de erro, ou '' em caso de sucesso
     */
    public static function storeUpload(string $channel, array $file): string
    {
        global $DB;

        if (!self::isValidChannel($channel)) {
            return __('Canal invalido.', 'wallpaper');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return __('Falha no upload do arquivo.', 'wallpaper');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return __('Arquivo invalido.', 'wallpaper');
        }

        // Valida o conteudo real do arquivo, nao a extensao: um .jpg pode
        // conter qualquer coisa.
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !isset($info['mime'])) {
            return __('O arquivo enviado nao e uma imagem valida.', 'wallpaper');
        }
        $mime = $info['mime'];
        if (!isset(self::ALLOWED_MIME[$mime])) {
            return sprintf(
                __('Formato %s nao suportado. Envie JPEG ou PNG.', 'wallpaper'),
                $mime
            );
        }

        $dir = self::getStorageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true)) {
            return __('Nao foi possivel criar o diretorio de armazenamento.', 'wallpaper');
        }

        $target = self::getFilePath($channel);
        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            return __('Nao foi possivel gravar o arquivo no servidor.', 'wallpaper');
        }
        @chmod($target, 0o644);

        $DB->update(self::getTable(), [
            'filename' => substr((string) $file['name'], 0, 255),
            'mime'     => $mime,
            'filesize' => (int) filesize($target),
            'width'    => (int) $info[0],
            'height'   => (int) $info[1],
            'users_id' => (int) Session::getLoginUserID(),
            'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['channel' => $channel]);

        return '';
    }

    /**
     * Copia a imagem do piloto para producao. A URL de producao nao muda:
     * so o conteudo servido por ela.
     *
     * @return string mensagem de erro, ou '' em caso de sucesso
     */
    public static function promote(): string
    {
        global $DB;

        $pilot = self::getChannel(self::CHANNEL_PILOT);
        if ($pilot === null || empty($pilot['mime'])) {
            return __('O canal piloto ainda nao tem imagem para promover.', 'wallpaper');
        }

        $source = self::getFilePath(self::CHANNEL_PILOT);
        if (!is_file($source)) {
            return __('O arquivo do canal piloto nao foi encontrado no disco.', 'wallpaper');
        }

        if (!@copy($source, self::getFilePath(self::CHANNEL_PRODUCTION))) {
            return __('Nao foi possivel copiar o arquivo para producao.', 'wallpaper');
        }

        $DB->update(self::getTable(), [
            'filename' => $pilot['filename'],
            'mime'     => $pilot['mime'],
            'filesize' => $pilot['filesize'],
            'width'    => $pilot['width'],
            'height'   => $pilot['height'],
            'users_id' => (int) Session::getLoginUserID(),
            'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['channel' => self::CHANNEL_PRODUCTION]);

        return '';
    }
}
