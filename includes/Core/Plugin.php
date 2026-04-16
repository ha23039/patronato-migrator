<?php
/**
 * Clase principal del plugin Patronato Migrator.
 *
 * @package PatronatoMigrator\Core
 */

declare(strict_types=1);

namespace PatronatoMigrator\Core;

defined('ABSPATH') || exit;

/**
 * Singleton que orquesta la inicializacion del plugin y actua como
 * contenedor minimo de dependencias compartidas.
 */
final class Plugin
{
    /**
     * Instancia unica del singleton.
     */
    private static ?self $instance = null;

    /**
     * Loader compartido para registro de hooks y AJAX.
     */
    private Loader $loader;

    /**
     * Slug usado como prefijo en los hook suffix de las paginas del plugin.
     */
    private const ADMIN_PAGE_SLUG = 'patronato-migrator';

    /**
     * Handle base usado para registrar scripts y estilos del plugin.
     */
    private const ASSET_HANDLE = 'patronato-migrator';

    /**
     * Inicializa dependencias internas. Privado para forzar el uso del singleton.
     */
    private function __construct()
    {
        $this->loader = new Loader();
    }

    /**
     * Devuelve la instancia unica del plugin.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Devuelve el Loader compartido.
     *
     * Otros componentes registran sus hooks a traves de el.
     *
     * @return Loader
     */
    public function getLoader(): Loader
    {
        return $this->loader;
    }

    /**
     * Devuelve el slug base usado por el menu admin del plugin.
     *
     * @return string
     */
    public function getAdminPageSlug(): string
    {
        return self::ADMIN_PAGE_SLUG;
    }

    /**
     * Punto de entrada llamado tras plugins_loaded.
     *
     * Carga el text domain, registra los hooks comunes y ejecuta el Loader.
     *
     * @return void
     */
    public function run(): void
    {
        $this->loadTextDomain();
        $this->registerHooks();
        $this->loader->run();
    }

    /**
     * Carga las traducciones del plugin.
     *
     * @return void
     */
    private function loadTextDomain(): void
    {
        load_plugin_textdomain(
            PATRONATO_MIGRATOR_TEXT_DOMAIN,
            false,
            dirname(PATRONATO_MIGRATOR_BASENAME) . '/languages'
        );
    }

    /**
     * Registra los hooks que pertenecen al bootstrap del plugin.
     *
     * Los hooks especificos del panel admin, los AJAX y los migrators
     * se registraran desde sus propios componentes en sprints posteriores.
     *
     * @return void
     */
    private function registerHooks(): void
    {
        $this->loader->addAction('admin_enqueue_scripts', $this, 'enqueueAdminAssets', 10, 1);
    }

    /**
     * Encola estilos y scripts del plugin solo en sus paginas admin.
     *
     * El gating combina dos comprobaciones:
     *  - El hook suffix recibido por admin_enqueue_scripts contiene el slug del plugin.
     *  - get_current_screen() devuelve un screen ID registrado por el Loader.
     *
     * Esto evita conflictos con otros plugins y nunca encola en el frontend.
     *
     * @param string $hook_suffix Hook suffix entregado por WordPress.
     * @return void
     */
    public function enqueueAdminAssets(string $hook_suffix): void
    {
        if (!$this->isPluginAdminScreen($hook_suffix)) {
            return;
        }

        wp_register_style(
            self::ASSET_HANDLE,
            PATRONATO_MIGRATOR_ASSETS_URL . 'css/migrator.css',
            [],
            PATRONATO_MIGRATOR_VERSION
        );

        wp_register_script(
            self::ASSET_HANDLE,
            PATRONATO_MIGRATOR_ASSETS_URL . 'js/migrator.js',
            ['jquery'],
            PATRONATO_MIGRATOR_VERSION,
            true
        );

        wp_localize_script(
            self::ASSET_HANDLE,
            'PatronatoMigratorConfig',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('pm_ajax_nonce'),
                'action'  => 'pm_run_batch',
            ]
        );

        wp_enqueue_style(self::ASSET_HANDLE);
        wp_enqueue_script(self::ASSET_HANDLE);
    }

    /**
     * Determina si la pantalla actual pertenece al plugin.
     *
     * @param string $hook_suffix Hook suffix recibido en admin_enqueue_scripts.
     * @return bool
     */
    private function isPluginAdminScreen(string $hook_suffix): bool
    {
        if ($hook_suffix !== '' && str_contains($hook_suffix, self::ADMIN_PAGE_SLUG)) {
            return true;
        }

        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if ($screen === null) {
            return false;
        }

        $screen_id        = (string) $screen->id;
        $registered_slugs = $this->loader->getPluginScreenIds();

        if (in_array($screen_id, $registered_slugs, true)) {
            return true;
        }

        return str_contains($screen_id, self::ADMIN_PAGE_SLUG);
    }
}
