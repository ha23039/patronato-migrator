<?php
/**
 * Plugin Name:       Patronato Migrator
 * Plugin URI:        https://app.patronatopormayor.cl
 * Description:       Migrador one-shot de JoomShopping a WooCommerce para Patronato Por Mayor. Plugin temporal: instalar, ejecutar la migracion y desinstalar.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Patronato Por Mayor
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       patronato-migrator
 * Domain Path:       /languages
 *
 * @package PatronatoMigrator
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Constantes globales del plugin.
 */
define('PATRONATO_MIGRATOR_VERSION', '1.0.0');
define('PATRONATO_MIGRATOR_FILE', __FILE__);
define('PATRONATO_MIGRATOR_PATH', plugin_dir_path(__FILE__));
define('PATRONATO_MIGRATOR_URL', plugin_dir_url(__FILE__));
define('PATRONATO_MIGRATOR_BASENAME', plugin_basename(__FILE__));
define('PATRONATO_MIGRATOR_INCLUDES_PATH', PATRONATO_MIGRATOR_PATH . 'includes/');
define('PATRONATO_MIGRATOR_ASSETS_URL', PATRONATO_MIGRATOR_URL . 'assets/');
define('PATRONATO_MIGRATOR_TEXT_DOMAIN', 'patronato-migrator');
define('PATRONATO_MIGRATOR_DB_VERSION', '1.0.0');

/**
 * Autoloader PSR-4 para el namespace raiz PatronatoMigrator\.
 *
 * Mapea PatronatoMigrator\Foo\Bar a includes/Foo/Bar.php.
 *
 * @param string $class Nombre cualificado de la clase a cargar.
 * @return void
 */
spl_autoload_register(static function (string $class): void {
    $prefix   = 'PatronatoMigrator\\';
    $base_dir = PATRONATO_MIGRATOR_INCLUDES_PATH;

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $relative_path  = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';
    $file           = $base_dir . $relative_path;

    if (is_readable($file)) {
        require_once $file;
    }
});

/**
 * Hooks de ciclo de vida del plugin.
 *
 * La activacion crea la tabla migration_log mediante dbDelta.
 * La desactivacion limpia opciones del plugin (cursores y credenciales)
 * pero NO elimina datos migrados ni el log historico de la migracion.
 */
register_activation_hook(__FILE__, [\PatronatoMigrator\Core\Activator::class, 'activate']);

register_deactivation_hook(__FILE__, static function (): void {
    global $wpdb;

    $option_names = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'pm\\_%'"
    );

    if (is_array($option_names)) {
        foreach ($option_names as $option_name) {
            delete_option($option_name);
        }
    }
});

/**
 * Bootstrap del plugin tras la carga de WordPress.
 */
add_action('plugins_loaded', static function (): void {
    \PatronatoMigrator\Core\Plugin::getInstance()->run();
});
