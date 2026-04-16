<?php
/**
 * Activador del plugin Patronato Migrator.
 *
 * @package PatronatoMigrator\Core
 */

declare(strict_types=1);

namespace PatronatoMigrator\Core;

defined('ABSPATH') || exit;

/**
 * Maneja la activacion del plugin: creacion del schema y registro de version.
 */
final class Activator
{
    /**
     * Nombre logico de la tabla, sin prefijo.
     */
    private const TABLE_NAME = 'migration_log';

    /**
     * Opcion donde se almacena la version del schema instalado.
     */
    private const DB_VERSION_OPTION = 'pm_db_version';

    /**
     * Punto de entrada del hook de activacion.
     *
     * Crea la tabla migration_log mediante dbDelta y persiste la version
     * del schema actual para soportar futuras migraciones.
     *
     * @return void
     */
    public static function activate(): void
    {
        self::createMigrationLogTable();
        update_option(self::DB_VERSION_OPTION, PATRONATO_MIGRATOR_DB_VERSION, false);
    }

    /**
     * Crea la tabla migration_log usando la API dbDelta de WordPress.
     *
     * El schema replica exactamente la definicion fijada en docs/AGENTS.md
     * (ENUMs de modulo y status, indices por modulo, status y fecha).
     *
     * @return void
     */
    private static function createMigrationLogTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name      = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            module ENUM('categories','products','images','customers','orders','redirects') NOT NULL,
            joomla_id BIGINT UNSIGNED NULL,
            wp_id BIGINT UNSIGNED NULL,
            status ENUM('success','warning','error','skipped') NOT NULL,
            message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_module (module),
            KEY idx_status (status),
            KEY idx_created (created_at)
        ) {$charset_collate};";

        dbDelta($sql);
    }
}
