<?php
/**
 * Endpoints AJAX del panel admin de Patronato Migrator.
 *
 * @package PatronatoMigrator\Admin
 */

declare(strict_types=1);

namespace PatronatoMigrator\Admin;

use PatronatoMigrator\Core\Loader;
use PatronatoMigrator\Database\JoomlaConnector;
use PatronatoMigrator\Database\MigrationRepository;
use PatronatoMigrator\Migrators\AbstractMigrator;
use PatronatoMigrator\Migrators\CategoryMigrator;
use PatronatoMigrator\Migrators\CustomerMigrator;
use PatronatoMigrator\Migrators\ImageMigrator;
use PatronatoMigrator\Migrators\OrderMigrator;
use PatronatoMigrator\Migrators\ProductMigrator;
use Throwable;

defined('ABSPATH') || exit;

/**
 * Recibe las peticiones AJAX del panel: prueba de conexion en Sprint 1,
 * ejecucion de batches en sprints posteriores.
 */
final class AjaxHandler
{
    /**
     * Nonce action compartido por todos los endpoints AJAX del plugin.
     */
    public const AJAX_NONCE_ACTION = 'pm_ajax_nonce';

    /**
     * Capability requerida para invocar cualquier endpoint AJAX.
     */
    private const REQUIRED_CAP = 'manage_options';

    /**
     * Tamano de batch por defecto cuando el frontend no envia uno.
     *
     * @var array<string, int>
     */
    private const DEFAULT_BATCH_SIZES = [
        'categories' => 316,
        'products'   => 100,
        'images'     => 50,
        'customers'  => 200,
        'orders'     => 50,
        'redirects'  => 100,
    ];

    /**
     * Registra los hooks del componente en el Loader compartido.
     */
    public function register(Loader $loader): void
    {
        $loader->addAction('wp_ajax_pm_test_connection', $this, 'handleTestConnection', 10, 0);
        $loader->addAction('wp_ajax_pm_run_batch', $this, 'handleRunBatch', 10, 0);
    }

    /**
     * Endpoint pm_test_connection. Recibe credenciales del formulario y
     * verifica la conectividad a Joomla sin tocar las credenciales guardadas.
     */
    public function handleTestConnection(): void
    {
        check_ajax_referer(self::AJAX_NONCE_ACTION, 'nonce');

        if (!current_user_can(self::REQUIRED_CAP)) {
            wp_send_json_error(
                ['message' => __('Acceso denegado.', 'patronato-migrator')],
                403
            );
        }

        $host     = sanitize_text_field((string) wp_unslash($_POST['host'] ?? ''));
        $port     = absint($_POST['port'] ?? 0);
        $database = sanitize_text_field((string) wp_unslash($_POST['database'] ?? ''));
        $username = sanitize_text_field((string) wp_unslash($_POST['username'] ?? ''));
        $password = (string) wp_unslash($_POST['password'] ?? '');

        if ($host === '' || $port <= 0 || $database === '' || $username === '' || $password === '') {
            wp_send_json_error(
                ['message' => __('Todos los campos son obligatorios para probar la conexion.', 'patronato-migrator')],
                400
            );
        }

        try {
            $connector = new JoomlaConnector([
                'host'     => $host,
                'port'     => $port,
                'database' => $database,
                'user'     => $username,
                'password' => $password,
                'charset'  => 'utf8mb4',
            ]);
        } catch (Throwable $e) {
            wp_send_json_error(
                ['message' => __('Credenciales con formato invalido.', 'patronato-migrator')],
                400
            );
        }

        if ($connector->test()) {
            wp_send_json_success(
                ['message' => __('Conexion exitosa a la base de datos Joomla.', 'patronato-migrator')]
            );
        }

        wp_send_json_error(
            ['message' => __('No se pudo conectar. Revisa host, puerto, base de datos y credenciales.', 'patronato-migrator')]
        );
    }

    /**
     * Endpoint pm_run_batch. Ejecuta un unico batch del modulo solicitado y
     * devuelve el progreso para que el navegador decida si seguir iterando.
     */
    public function handleRunBatch(): void
    {
        check_ajax_referer(self::AJAX_NONCE_ACTION, 'nonce');

        if (!current_user_can(self::REQUIRED_CAP)) {
            wp_send_json_error(
                ['message' => __('Acceso denegado.', 'patronato-migrator')],
                403
            );
        }

        $module    = sanitize_key((string) ($_POST['module'] ?? ''));
        $batchSize = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 0;

        if ($module === '' || !isset(self::DEFAULT_BATCH_SIZES[$module])) {
            wp_send_json_error(
                ['message' => __('Modulo desconocido.', 'patronato-migrator')],
                400
            );
        }

        if ($batchSize <= 0) {
            $batchSize = self::DEFAULT_BATCH_SIZES[$module];
        }

        try {
            $migrator = $this->buildMigrator($module);
            $result   = $migrator->run($batchSize);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Resuelve el migrador concreto para el modulo dado.
     *
     * @throws Throwable Si el modulo no esta soportado en este sprint.
     */
    private function buildMigrator(string $module): AbstractMigrator
    {
        $repository = new MigrationRepository();
        $connector  = new JoomlaConnector();

        switch ($module) {
            case 'categories':
                return new CategoryMigrator($connector, $repository);
            case 'products':
                return new ProductMigrator($connector, $repository);
            case 'images':
                return new ImageMigrator($connector, $repository);
            case 'customers':
                return new CustomerMigrator($connector, $repository);
            case 'orders':
                return new OrderMigrator($connector, $repository);
        }

        throw new \RuntimeException(
            sprintf('Modulo "%s" aun no esta implementado.', $module)
        );
    }
}
