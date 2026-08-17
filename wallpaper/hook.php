<?php

/**
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Wallpaper\Wallpaper;

function plugin_wallpaper_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_WALLPAPER_VERSION);
    $table     = Wallpaper::getTable();

    if (!$DB->tableExists($table)) {
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        $DB->doQuery(
            "CREATE TABLE `{$table}` (
                `id`       int {$key_sign} NOT NULL AUTO_INCREMENT,
                `channel`  varchar(20) NOT NULL,
                `filename` varchar(255) DEFAULT NULL,
                `mime`     varchar(100) DEFAULT NULL,
                `etag`     varchar(64) DEFAULT NULL,
                `filesize` int NOT NULL DEFAULT 0,
                `width`    int NOT NULL DEFAULT 0,
                `height`   int NOT NULL DEFAULT 0,
                `users_id` int {$key_sign} NOT NULL DEFAULT 0,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `channel` (`channel`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
        );
    }

    // Instalacoes anteriores a 1.1.0 nao tinham a coluna de ETag. Fica nula:
    // Wallpaper::getEtag() calcula e persiste no primeiro acesso a cada canal.
    $migration->addField($table, 'etag', 'string', ['value' => null, 'after' => 'mime']);

    // Os canais sao fixos: as URLs cadastradas no Intune dependem disso.
    foreach (Wallpaper::CHANNELS as $channel) {
        $exists = $DB->request([
            'FROM'  => $table,
            'WHERE' => ['channel' => $channel],
            'LIMIT' => 1,
        ])->current();

        if (!$exists) {
            $DB->insert($table, ['channel' => $channel]);
        }
    }

    // setConfigurationValues nao sobrescreve chaves ja existentes: instalacoes
    // antigas mantem o que o administrador configurou e apenas ganham as novas.
    Config::setConfigurationValues(Wallpaper::CONFIG_CONTEXT, [
        // Filtro de IP desligado por padrao: maquinas cloud-native saem de
        // qualquer rede, e atras de CDN o REMOTE_ADDR nem seria o do device.
        'allowed_networks' => '',
        'trusted_proxies'  => '',
        'cache_ttl'        => (string) Wallpaper::DEFAULT_CACHE_TTL,
        'client_ip_header' => 'X-Forwarded-For',
    ]);

    // Sem acesso por padrao em todos os perfis...
    // Guarda de idempotencia: no update o direito ja existe (instalado em versao anterior).
    // Sem isto, addProfileRights lanca "Duplicate entry" e trava o update do plugin.
    if (!countElementsInTable('glpi_profilerights', ['name' => Wallpaper::$rightname])) {
        ProfileRight::addProfileRights([Wallpaper::$rightname]);
    }
    // ...exceto para quem ja administra a configuracao do GLPI.
    $migration->addRight(
        Wallpaper::$rightname,
        READ | UPDATE | Wallpaper::RIGHT_PROMOTE,
        [Config::$rightname => UPDATE]
    );

    $dir = Wallpaper::getStorageDir();
    if (!is_dir($dir) && !@mkdir($dir, 0o755, true)) {
        $migration->displayWarning(
            "Nao foi possivel criar {$dir}. Crie o diretorio manualmente com permissao de escrita para o servidor web."
        );
    }

    $migration->executeMigration();

    return true;
}

function plugin_wallpaper_uninstall(): bool
{
    global $DB;

    $table = Wallpaper::getTable();
    if ($DB->tableExists($table)) {
        $DB->doQuery("DROP TABLE `{$table}`");
    }

    Config::deleteConfigurationValues(
        Wallpaper::CONFIG_CONTEXT,
        ['allowed_networks', 'trusted_proxies', 'cache_ttl', 'client_ip_header']
    );

    ProfileRight::deleteProfileRights([Wallpaper::$rightname]);

    // As imagens em GLPI_PLUGIN_DOC_DIR/wallpaper sao preservadas de proposito:
    // reinstalar o plugin nao deve destruir o que a equipe subiu.

    return true;
}
