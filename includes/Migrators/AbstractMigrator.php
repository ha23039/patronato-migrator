<?php
/**
 * Clase base abstracta de los migradores Joomla -> WooCommerce.
 *
 * @package PatronatoMigrator\Migrators
 */

declare(strict_types=1);

namespace PatronatoMigrator\Migrators;

use PatronatoMigrator\Database\JoomlaConnector;
use PatronatoMigrator\Database\MigrationRepository;
use Throwable;

defined('ABSPATH') || exit;

/**
 * AbstractMigrator implementa la mecanica comun de batch, cursor, log y
 * rollback. Cada migrador concreto solo debe implementar la logica especifica
 * de su entidad mediante los cuatro metodos abstractos.
 */
abstract class AbstractMigrator
{
    protected JoomlaConnector $connector;

    protected MigrationRepository $repository;

    public function __construct(JoomlaConnector $connector, MigrationRepository $repository)
    {
        $this->connector  = $connector;
        $this->repository = $repository;
    }

    /**
     * Identificador unico del modulo. Debe coincidir con uno de los valores
     * en MigrationRepository::MODULES.
     */
    abstract protected function getModuleName(): string;

    /**
     * Total de registros del origen (filtrados por publish_flag cuando aplica).
     */
    abstract public function getTotalCount(): int;

    /**
     * Procesa un batch de la entidad. Recibe offset y limite.
     *
     * @return array{processed:int,errors:int,skipped:int}
     */
    abstract protected function processBatch(int $offset, int $limit): array;

    /**
     * Elimina los registros migrados por este modulo. Ejecutado por rollback().
     */
    abstract protected function rollbackModule(): void;

    /**
     * Orquesta un batch: lee cursor -> ejecuta processBatch -> persiste cursor.
     *
     * Se llama una vez por peticion AJAX. El navegador es responsable de
     * iterar invocando run() hasta recibir done = true.
     *
     * @return array{
     *   processed:int,
     *   errors:int,
     *   skipped:int,
     *   cursor:int,
     *   total:int,
     *   percentage:float,
     *   done:bool,
     *   message:string
     * }
     */
    public function run(int $batchSize = 100): array
    {
        $batchSize = max(1, $batchSize);

        // Liberar memoria entre batches y subir el limite cuando sea posible.
        wp_raise_memory_limit('admin');

        $offset = $this->getCursor();
        $total  = $this->getTotalCount();

        if ($total === 0 || $offset >= $total) {
            return $this->buildResult(0, 0, 0, $offset, $total, true, __('No hay registros pendientes.', 'patronato-migrator'));
        }

        try {
            $result = $this->processBatch($offset, $batchSize);
        } catch (Throwable $e) {
            $this->log('error', null, null, sprintf('Excepcion en batch: %s', $e->getMessage()));

            return $this->buildResult(0, 1, 0, $offset, $total, false, $e->getMessage());
        }

        $processed = (int) ($result['processed'] ?? 0);
        $errors    = (int) ($result['errors'] ?? 0);
        $skipped   = (int) ($result['skipped'] ?? 0);

        $advance      = $processed + $errors + $skipped;
        $newCursor    = $offset + ($advance > 0 ? $advance : $batchSize);
        $newCursor    = min($newCursor, $total);

        $this->saveCursor($newCursor);

        // Limpiar caches entre batches; reduce el footprint cuando se procesan
        // miles de posts en una misma sesion del navegador.
        wp_cache_flush();

        $done    = $newCursor >= $total;
        $message = sprintf(
            __('Batch completado. %1$d procesados, %2$d errores, %3$d omitidos.', 'patronato-migrator'),
            $processed,
            $errors,
            $skipped
        );

        return $this->buildResult($processed, $errors, $skipped, $newCursor, $total, $done, $message);
    }

    /**
     * Devuelve el progreso actual sin ejecutar batches.
     *
     * @return array{
     *   total:int,
     *   processed:int,
     *   percentage:float,
     *   status:string
     * }
     */
    public function getProgress(): array
    {
        $total     = $this->getTotalCount();
        $processed = $this->getCursor();
        $percent   = $this->calcPercentage($processed, $total);

        $status = 'pending';
        if ($processed > 0 && $processed < $total) {
            $status = 'in_progress';
        } elseif ($processed >= $total && $total > 0) {
            $status = 'done';
        }

        return [
            'total'      => $total,
            'processed'  => $processed,
            'percentage' => $percent,
            'status'     => $status,
        ];
    }

    /**
     * Revierte la migracion del modulo: borra registros, resetea cursor y
     * limpia el log del modulo.
     */
    public function rollback(): void
    {
        $this->rollbackModule();
        $this->resetCursor();
        $this->repository->deleteByModule($this->getModuleName());
    }

    /**
     * Persiste una entrada en migration_log para el modulo actual.
     */
    protected function log(string $status, ?int $joomlaId, ?int $wpId, string $message): void
    {
        $this->repository->insert(
            $this->getModuleName(),
            $status,
            $joomlaId,
            $wpId,
            $message
        );
    }

    /**
     * Lee el cursor (offset procesado) desde wp_options.
     */
    protected function getCursor(): int
    {
        $key   = $this->cursorKey();
        $value = get_option($key, 0);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Persiste el cursor del modulo. autoload=no por ser hot-path de escritura.
     */
    protected function saveCursor(int $offset): void
    {
        update_option($this->cursorKey(), max(0, $offset), false);
    }

    /**
     * Resetea el cursor del modulo a 0.
     */
    protected function resetCursor(): void
    {
        delete_option($this->cursorKey());
    }

    /**
     * Calcula el porcentaje [0, 100] con un decimal.
     */
    protected function calcPercentage(int $processed, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(min(100, ($processed / $total) * 100), 1);
    }

    /**
     * Convencion de naming para la opcion del cursor.
     */
    private function cursorKey(): string
    {
        return 'pm_cursor_' . $this->getModuleName();
    }

    /**
     * Construye el array de resultado uniforme devuelto por run().
     *
     * @return array{
     *   processed:int,
     *   errors:int,
     *   skipped:int,
     *   cursor:int,
     *   total:int,
     *   percentage:float,
     *   done:bool,
     *   message:string
     * }
     */
    private function buildResult(
        int $processed,
        int $errors,
        int $skipped,
        int $cursor,
        int $total,
        bool $done,
        string $message
    ): array {
        return [
            'processed'  => $processed,
            'errors'     => $errors,
            'skipped'    => $skipped,
            'cursor'     => $cursor,
            'total'      => $total,
            'percentage' => $this->calcPercentage($cursor, $total),
            'done'       => $done,
            'message'    => $message,
        ];
    }
}
