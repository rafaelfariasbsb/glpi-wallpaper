<?php

/**
 * Stubs minimos do GLPI para exercitar a entrega da imagem fora de uma instancia.
 *
 * Substitui apenas o que o endpoint toca: Config, Event, o $DB e as excecoes
 * HTTP. A logica sob teste (ImageResponse, Wallpaper, NetworkFilter e o proprio
 * front/image.php) e a real, sem copia.
 *
 * @license GPL-3.0-or-later
 */

define('GLPI_PLUGIN_DOC_DIR', sys_get_temp_dir() . '/wallpaper-endpoint-test');

// Constantes de direito do GLPI, usadas pela classe sob teste.
define('READ', 1);
define('UPDATE', 2);
define('CREATE', 4);
define('DELETE', 8);
define('PURGE', 16);

/** Base do GLPI para itemtypes; nada dela e exercitado na entrega da imagem. */
class CommonDBTM
{
}

require __DIR__ . '/exceptions.php';
require __DIR__ . '/../../wallpaper/src/NetworkFilter.php';
require __DIR__ . '/../../wallpaper/src/Wallpaper.php';
require __DIR__ . '/../../wallpaper/src/ImageResponse.php';

if (!is_dir(GLPI_PLUGIN_DOC_DIR . '/wallpaper')) {
    mkdir(GLPI_PLUGIN_DOC_DIR . '/wallpaper', 0o755, true);
}

/** Estado do "banco", em arquivo para persistir entre requisicoes. */
final class FakeState
{
    public static function file(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/state.json';
    }

    public static function load(): array
    {
        $raw = @file_get_contents(self::file());
        if ($raw === false) {
            return ['channels' => [], 'config' => []];
        }
        return json_decode($raw, true) ?: ['channels' => [], 'config' => []];
    }

    public static function save(array $state): void
    {
        file_put_contents(self::file(), json_encode($state));
    }
}

final class FakeDB
{
    public function request(array $query): ArrayIterator
    {
        $state   = FakeState::load();
        $channel = $query['WHERE']['channel'] ?? null;
        $row     = $state['channels'][$channel] ?? null;

        return new ArrayIterator($row === null ? [] : [$row]);
    }

    public function update(string $table, array $values, array $where): bool
    {
        $state   = FakeState::load();
        $channel = $where['channel'];
        $state['channels'][$channel] = array_merge($state['channels'][$channel] ?? [], $values);
        FakeState::save($state);

        return true;
    }
}

$DB = new FakeDB();

class Config
{
    public static function getConfigurationValues(string $context): array
    {
        return FakeState::load()['config'] ?? [];
    }
}

class Event
{
    public static function log($items_id, $itemtype, $level, $service, $event): void
    {
        error_log('[wallpaper] ' . $event);
    }
}

class Session
{
    public static function getLoginUserID()
    {
        return 0;
    }
}

$CFG_GLPI = ['url_base' => 'http://127.0.0.1:8899', 'root_doc' => ''];
