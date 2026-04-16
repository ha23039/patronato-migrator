<?php
/**
 * Repositorio de la tabla migration_log.
 *
 * @package PatronatoMigrator\Database
 */

declare(strict_types=1);

namespace PatronatoMigrator\Database;

use RuntimeException;
use wpdb;

defined('ABSPATH') || exit;

/**
 * Encapsula todas las operaciones contra la tabla {prefix}migration_log.
 *
 * Junto con JoomlaConnector, este es el unico archivo donde se permite
 * escribir SQL crudo (a traves de la API $wpdb). Cualquier otro componente
 * debe interactuar con el log mediante esta clase.
 */
final class MigrationRepository
{
    /**
     * Modulos validos. Coincide con el ENUM definido en Activator.
     *
     * @var array<int, string>
     */
    public const MODULES = [
        'categories',
        'products',
        'images',
        'customers',
        'orders',
        'redirects',
    ];

    /**
     * Estados validos. Coincide con el ENUM definido en Activator.
     *
     * @var array<int, string>
     */
    public const STATUSES = ['success', 'warning', 'error', 'skipped'];

    private wpdb $db;

    private string $table;

    public function __construct(?wpdb $db = null)
    {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }

        $this->db    = $db;
        $this->table = $db->prefix . 'migration_log';
    }

    /**
     * Inserta una entrada en el log.
     *
     * @throws RuntimeException Si modulo o status no son validos.
     */
    public function insert(
        string $module,
        string $status,
        ?int $joomlaId,
        ?int $wpId,
        string $message
    ): void {
        $this->assertModule($module);
        $this->assertStatus($status);

        $data = [
            'module'    => $module,
            'status'    => $status,
            'joomla_id' => $joomlaId,
            'wp_id'     => $wpId,
            'message'   => $message,
        ];

        $formats = ['%s', '%s', '%d', '%d', '%s'];

        // wpdb::insert ignora valores null, no necesita reemplazo manual.
        $this->db->insert($this->table, $data, $formats);
    }

    /**
     * Cuenta entradas filtrando por modulo y opcionalmente por status.
     */
    public function countByModule(string $module, string $status = ''): int
    {
        $this->assertModule($module);

        if ($status === '') {
            $sql = $this->db->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE module = %s",
                $module
            );
        } else {
            $this->assertStatus($status);
            $sql = $this->db->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE module = %s AND status = %s",
                $module,
                $status
            );
        }

        return (int) $this->db->get_var($sql);
    }

    /**
     * Devuelve las ultimas entradas del modulo, ordenadas por id descendente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByModule(string $module, int $limit = 100, int $offset = 0): array
    {
        $this->assertModule($module);

        $limit  = max(1, $limit);
        $offset = max(0, $offset);

        $sql = $this->db->prepare(
            "SELECT id, module, joomla_id, wp_id, status, message, created_at
             FROM {$this->table}
             WHERE module = %s
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            $module,
            $limit,
            $offset
        );

        $rows = $this->db->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Borra todas las entradas asociadas a un modulo.
     */
    public function deleteByModule(string $module): void
    {
        $this->assertModule($module);

        $this->db->delete($this->table, ['module' => $module], ['%s']);
    }

    /**
     * Exporta a CSV las entradas filtradas por modulo (o todas si vacio).
     * Devuelve la ruta absoluta del archivo generado en wp-content/uploads/patronato-migrator/.
     *
     * @throws RuntimeException Si no se puede crear el directorio o escribir el archivo.
     */
    public function exportCsv(string $module = ''): string
    {
        if ($module !== '') {
            $this->assertModule($module);
        }

        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            throw new RuntimeException('No se pudo determinar el directorio de uploads.');
        }

        $dir = trailingslashit($upload['basedir']) . 'patronato-migrator';
        if (!wp_mkdir_p($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de exports.');
        }

        $filename = sprintf(
            'migration-log-%s-%s.csv',
            $module !== '' ? $module : 'all',
            gmdate('Ymd-His')
        );
        $path = trailingslashit($dir) . $filename;

        $handle = fopen($path, 'w');
        if (!is_resource($handle)) {
            throw new RuntimeException('No se pudo abrir el archivo de export para escritura.');
        }

        try {
            fputcsv($handle, ['id', 'module', 'joomla_id', 'wp_id', 'status', 'message', 'created_at']);

            $batch  = 1000;
            $offset = 0;

            while (true) {
                if ($module === '') {
                    $sql = $this->db->prepare(
                        "SELECT id, module, joomla_id, wp_id, status, message, created_at
                         FROM {$this->table}
                         ORDER BY id ASC
                         LIMIT %d OFFSET %d",
                        $batch,
                        $offset
                    );
                } else {
                    $sql = $this->db->prepare(
                        "SELECT id, module, joomla_id, wp_id, status, message, created_at
                         FROM {$this->table}
                         WHERE module = %s
                         ORDER BY id ASC
                         LIMIT %d OFFSET %d",
                        $module,
                        $batch,
                        $offset
                    );
                }

                $rows = $this->db->get_results($sql, ARRAY_A);
                if (!is_array($rows) || $rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    fputcsv($handle, [
                        (string) ($row['id'] ?? ''),
                        (string) ($row['module'] ?? ''),
                        $row['joomla_id'] !== null ? (string) $row['joomla_id'] : '',
                        $row['wp_id'] !== null ? (string) $row['wp_id'] : '',
                        (string) ($row['status'] ?? ''),
                        (string) ($row['message'] ?? ''),
                        (string) ($row['created_at'] ?? ''),
                    ]);
                }

                $offset += $batch;

                if (count($rows) < $batch) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    /**
     * @throws RuntimeException
     */
    private function assertModule(string $module): void
    {
        if (!in_array($module, self::MODULES, true)) {
            throw new RuntimeException(sprintf('Modulo de migracion invalido: %s.', $module));
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException(sprintf('Status de migracion invalido: %s.', $status));
        }
    }
}
