<?php
/**
 * Registra el menu admin del plugin Patronato Migrator y maneja el guardado
 * del formulario de configuracion.
 *
 * @package PatronatoMigrator\Admin
 */

declare(strict_types=1);

namespace PatronatoMigrator\Admin;

use PatronatoMigrator\Core\Loader;
use PatronatoMigrator\Helpers\Encryptor;
use Throwable;

defined('ABSPATH') || exit;

/**
 * Encapsula la creacion del menu admin y el handler de admin-post para
 * guardar la configuracion de conexion a Joomla.
 */
final class AdminMenu
{
    /**
     * Slug del menu principal (dashboard).
     */
    public const SLUG_DASHBOARD = 'patronato-migrator';

    /**
     * Slug del submenu de configuracion.
     */
    public const SLUG_CONFIG = 'patronato-migrator-config';

    /**
     * Opcion donde se almacenan las credenciales cifradas.
     */
    public const OPTION_CREDENTIALS = 'pm_joomla_credentials';

    /**
     * Opcion donde se almacena la ruta absoluta a las imagenes de Joomla.
     */
    public const OPTION_IMAGES_PATH = 'pm_joomla_images_path';

    /**
     * Capability requerida para acceder al panel.
     */
    private const REQUIRED_CAP = 'manage_options';

    /**
     * Nonce action usado por el formulario de guardado.
     */
    private const SAVE_NONCE_ACTION = 'pm_save_config';

    /**
     * Action hook usado por admin-post para el guardado.
     */
    private const SAVE_POST_ACTION = 'pm_save_config';

    /**
     * Registra los hooks del componente en el Loader compartido.
     */
    public function register(Loader $loader): void
    {
        $loader->addAction('admin_menu', $this, 'registerMenu', 10, 0);
        $loader->addAction('admin_post_' . self::SAVE_POST_ACTION, $this, 'handleSaveConfig', 10, 0);
    }

    /**
     * Registra el menu principal y el submenu de configuracion en wp-admin.
     */
    public function registerMenu(): void
    {
        add_menu_page(
            __('Patronato Migrator', 'patronato-migrator'),
            __('Patronato Migrator', 'patronato-migrator'),
            self::REQUIRED_CAP,
            self::SLUG_DASHBOARD,
            [$this, 'renderDashboard'],
            'dashicons-database-import',
            58
        );

        add_submenu_page(
            self::SLUG_DASHBOARD,
            __('Configuracion', 'patronato-migrator'),
            __('Configuracion', 'patronato-migrator'),
            self::REQUIRED_CAP,
            self::SLUG_CONFIG,
            [$this, 'renderConfig']
        );
    }

    /**
     * Renderiza la vista del dashboard.
     */
    public function renderDashboard(): void
    {
        if (!current_user_can(self::REQUIRED_CAP)) {
            wp_die(esc_html__('Acceso denegado.', 'patronato-migrator'), '', ['response' => 403]);
        }

        require PATRONATO_MIGRATOR_INCLUDES_PATH . 'Admin/Views/dashboard.php';
    }

    /**
     * Renderiza la vista de configuracion. Carga credenciales descifradas
     * sin exponer el password al markup.
     */
    public function renderConfig(): void
    {
        if (!current_user_can(self::REQUIRED_CAP)) {
            wp_die(esc_html__('Acceso denegado.', 'patronato-migrator'), '', ['response' => 403]);
        }

        $stored = $this->loadStoredCredentials();

        $creds = [
            'host'        => isset($stored['host']) ? (string) $stored['host'] : '',
            'port'        => isset($stored['port']) ? (string) $stored['port'] : '3306',
            'database'    => isset($stored['database']) ? (string) $stored['database'] : '',
            'username'    => isset($stored['user']) ? (string) $stored['user'] : '',
            'images_path' => (string) get_option(self::OPTION_IMAGES_PATH, ''),
        ];

        require PATRONATO_MIGRATOR_INCLUDES_PATH . 'Admin/Views/config.php';
    }

    /**
     * Procesa el POST del formulario de configuracion. Cifra credenciales,
     * persiste opciones y redirige con un mensaje.
     */
    public function handleSaveConfig(): void
    {
        if (!current_user_can(self::REQUIRED_CAP)) {
            wp_die(esc_html__('Acceso denegado.', 'patronato-migrator'), '', ['response' => 403]);
        }

        check_admin_referer(self::SAVE_NONCE_ACTION, '_wpnonce');

        $host        = sanitize_text_field((string) wp_unslash($_POST['host'] ?? ''));
        $port        = absint($_POST['port'] ?? 0);
        $database    = sanitize_text_field((string) wp_unslash($_POST['database'] ?? ''));
        $username    = sanitize_text_field((string) wp_unslash($_POST['username'] ?? ''));
        $passwordRaw = (string) wp_unslash($_POST['password'] ?? '');
        $imagesPath  = sanitize_text_field((string) wp_unslash($_POST['images_path'] ?? ''));

        if ($host === '' || $port <= 0 || $database === '' || $username === '' || $imagesPath === '') {
            $this->redirectToConfig('invalid');
        }

        $existing = $this->loadStoredCredentials();
        $password = $passwordRaw !== '' ? $passwordRaw : (string) ($existing['password'] ?? '');

        if ($password === '') {
            $this->redirectToConfig('invalid');
        }

        $payload = [
            'host'     => $host,
            'port'     => $port,
            'database' => $database,
            'user'     => $username,
            'password' => $password,
            'charset'  => 'utf8mb4',
        ];

        $encoded = wp_json_encode($payload);
        if (!is_string($encoded)) {
            $this->redirectToConfig('invalid');
        }

        try {
            $cipher = Encryptor::encrypt($encoded);
        } catch (Throwable $e) {
            $this->redirectToConfig('invalid');
        }

        update_option(self::OPTION_CREDENTIALS, $cipher, false);
        update_option(self::OPTION_IMAGES_PATH, $imagesPath, false);

        $this->redirectToConfig('saved');
    }

    /**
     * Lee y descifra las credenciales almacenadas.
     *
     * @return array<string, mixed> Array con keys host/port/database/user/password
     *                              o vacio si no hay credenciales o estan corruptas.
     */
    private function loadStoredCredentials(): array
    {
        $stored = get_option(self::OPTION_CREDENTIALS);

        if (!is_string($stored) || $stored === '') {
            return [];
        }

        try {
            $plain = Encryptor::decrypt($stored);
        } catch (Throwable $e) {
            return [];
        }

        $decoded = json_decode($plain, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Redirige a la vista de configuracion con un mensaje y termina ejecucion.
     */
    private function redirectToConfig(string $message): void
    {
        $url = add_query_arg(
            [
                'page'   => self::SLUG_CONFIG,
                'pm_msg' => $message,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }
}
