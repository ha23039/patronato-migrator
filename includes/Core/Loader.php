<?php
/**
 * Registrador de hooks del plugin Patronato Migrator.
 *
 * @package PatronatoMigrator\Core
 */

declare(strict_types=1);

namespace PatronatoMigrator\Core;

defined('ABSPATH') || exit;

/**
 * Almacena y registra las acciones, filtros y endpoints AJAX del plugin.
 *
 * Patron clasico de loader: las dependencias se acumulan en arrays y se
 * confirman ante WordPress en una unica llamada a {@see self::run()}.
 */
final class Loader
{
    /**
     * Acciones pendientes de registrar.
     *
     * @var array<int, array{hook:string, component:object|string, callback:string, priority:int, accepted_args:int}>
     */
    private array $actions = [];

    /**
     * Filtros pendientes de registrar.
     *
     * @var array<int, array{hook:string, component:object|string, callback:string, priority:int, accepted_args:int}>
     */
    private array $filters = [];

    /**
     * Handles de pagina admin permitidos para enqueuing de assets.
     *
     * @var array<int, string>
     */
    private array $pluginScreenIds = [];

    /**
     * Registra una accion de WordPress.
     *
     * @param string         $hook          Nombre de la accion.
     * @param object|string  $component     Instancia o nombre de clase que aloja el callback.
     * @param string         $callback      Metodo a invocar.
     * @param int            $priority      Prioridad del hook.
     * @param int            $accepted_args Numero de argumentos aceptados.
     * @return void
     */
    public function addAction(
        string $hook,
        object|string $component,
        string $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        $this->actions[] = [
            'hook'          => $hook,
            'component'     => $component,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        ];
    }

    /**
     * Registra un filtro de WordPress.
     *
     * @param string         $hook          Nombre del filtro.
     * @param object|string  $component     Instancia o nombre de clase que aloja el callback.
     * @param string         $callback      Metodo a invocar.
     * @param int            $priority      Prioridad del hook.
     * @param int            $accepted_args Numero de argumentos aceptados.
     * @return void
     */
    public function addFilter(
        string $hook,
        object|string $component,
        string $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        $this->filters[] = [
            'hook'          => $hook,
            'component'     => $component,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        ];
    }

    /**
     * Declara un screen ID como propio del plugin para gating de assets.
     *
     * @param string $screen_id Identificador de pantalla devuelto por get_current_screen().
     * @return void
     */
    public function registerPluginScreen(string $screen_id): void
    {
        if ($screen_id !== '' && !in_array($screen_id, $this->pluginScreenIds, true)) {
            $this->pluginScreenIds[] = $screen_id;
        }
    }

    /**
     * Devuelve la lista de screen IDs registrados como propios del plugin.
     *
     * @return array<int, string>
     */
    public function getPluginScreenIds(): array
    {
        return $this->pluginScreenIds;
    }

    /**
     * Confirma todas las acciones y filtros acumulados en el core de WordPress.
     *
     * @return void
     */
    public function run(): void
    {
        foreach ($this->filters as $filter) {
            add_filter(
                $filter['hook'],
                [$filter['component'], $filter['callback']],
                $filter['priority'],
                $filter['accepted_args']
            );
        }

        foreach ($this->actions as $action) {
            add_action(
                $action['hook'],
                [$action['component'], $action['callback']],
                $action['priority'],
                $action['accepted_args']
            );
        }
    }
}
