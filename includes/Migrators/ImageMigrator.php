<?php
/**
 * Migrador de imagenes JoomShopping -> media library de WordPress.
 *
 * @package PatronatoMigrator\Migrators
 */

declare(strict_types=1);

namespace PatronatoMigrator\Migrators;

use Throwable;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Sideload de imagenes desde la ruta absoluta de Joomla al media library
 * de WordPress. Asigna la imagen principal como thumbnail y las adicionales
 * como _product_image_gallery. Idempotente: salta productos que ya tienen
 * thumbnail asignado.
 */
final class ImageMigrator extends AbstractMigrator
{
    private const META_JOOMLA_PRODUCT_ID = '_joomla_product_id';
    private const META_GALLERY           = '_product_image_gallery';
    private const META_ATTACHMENT_TAG    = '_joomla_image_attachment';
    private const OPTION_IMAGES_PATH     = 'pm_joomla_images_path';
    private const ROLLBACK_BATCH_SIZE    = 200;

    /**
     * Cache del basedir resuelto via realpath. Se calcula una vez por batch.
     */
    private ?string $resolvedBaseDir = null;

    protected function getModuleName(): string
    {
        return 'images';
    }

    public function getTotalCount(): int
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type = %s",
            self::META_JOOMLA_PRODUCT_ID,
            'product'
        );

        return (int) $wpdb->get_var($sql);
    }

    /**
     * @return array{processed:int,errors:int,skipped:int}
     */
    protected function processBatch(int $offset, int $limit): array
    {
        $this->resolvedBaseDir = $this->resolveBaseDir();

        $productIds = $this->fetchProductBatch($offset, $limit);

        if ($productIds === []) {
            return ['processed' => 0, 'errors' => 0, 'skipped' => 0];
        }

        $joomlaIdByWpId = [];
        foreach ($productIds as $wpId) {
            $joomlaId = (int) get_post_meta($wpId, self::META_JOOMLA_PRODUCT_ID, true);
            if ($joomlaId > 0) {
                $joomlaIdByWpId[$wpId] = $joomlaId;
            }
        }

        $joomlaIds = array_values(array_unique(array_map('intval', $joomlaIdByWpId)));
        $mainImagesByJoomlaId    = $this->fetchMainImages($joomlaIds);
        $galleryImagesByJoomlaId = $this->fetchGalleryImages($joomlaIds);

        $this->loadMediaDependencies();

        $processed = 0;
        $errors    = 0;
        $skipped   = 0;

        foreach ($productIds as $wpId) {
            $joomlaId = $joomlaIdByWpId[$wpId] ?? 0;
            if ($joomlaId <= 0) {
                $skipped++;
                $this->log('skipped', null, $wpId, 'Producto WP sin _joomla_product_id; se omite.');
                continue;
            }

            if (has_post_thumbnail($wpId)) {
                $skipped++;
                $this->log('skipped', $joomlaId, $wpId, 'Producto ya tiene thumbnail; se omite.');
                continue;
            }

            try {
                $outcome = $this->processProductImages(
                    $wpId,
                    $joomlaId,
                    (string) ($mainImagesByJoomlaId[$joomlaId] ?? ''),
                    $galleryImagesByJoomlaId[$joomlaId] ?? []
                );
            } catch (Throwable $e) {
                $errors++;
                $this->log('error', $joomlaId, $wpId, sprintf('Excepcion en sideload: %s', $e->getMessage()));
                continue;
            }

            switch ($outcome) {
                case 'success':
                    $processed++;
                    break;
                case 'warning':
                    $processed++;
                    break;
                case 'skipped':
                    $skipped++;
                    break;
                case 'error':
                default:
                    $errors++;
                    break;
            }
        }

        return [
            'processed' => $processed,
            'errors'    => $errors,
            'skipped'   => $skipped,
        ];
    }

    protected function rollbackModule(): void
    {
        global $wpdb;

        // Borrar attachments creados por este modulo (tag _joomla_image_attachment).
        while (true) {
            $sql = $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND p.post_type = %s
                 LIMIT %d",
                self::META_ATTACHMENT_TAG,
                'attachment',
                self::ROLLBACK_BATCH_SIZE
            );

            $ids = $wpdb->get_col($sql);

            if (!is_array($ids) || $ids === []) {
                break;
            }

            foreach ($ids as $attachmentId) {
                wp_delete_attachment((int) $attachmentId, true);
            }
        }

        // Limpiar metas residuales _thumbnail_id y _product_image_gallery
        // de productos cuyas attachments ya no existen.
        $productIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             WHERE pm.meta_key = %s",
            self::META_JOOMLA_PRODUCT_ID
        ));

        if (is_array($productIds)) {
            foreach ($productIds as $productId) {
                $thumbId = (int) get_post_meta((int) $productId, '_thumbnail_id', true);
                if ($thumbId > 0 && get_post($thumbId) === null) {
                    delete_post_meta((int) $productId, '_thumbnail_id');
                }
                delete_post_meta((int) $productId, self::META_GALLERY);
            }
        }
    }

    /**
     * Procesa la imagen principal y la galeria de un producto.
     *
     * @param array<int, string> $galleryFiles
     * @return string  'success' | 'warning' | 'skipped' | 'error'
     */
    private function processProductImages(int $wpId, int $joomlaId, string $mainImage, array $galleryFiles): string
    {
        $hadWarnings = false;

        $mainAttachmentId = null;

        if ($mainImage !== '') {
            $mainAttachmentId = $this->sideloadImage($mainImage, $wpId, $joomlaId);
            if ($mainAttachmentId !== null) {
                set_post_thumbnail($wpId, $mainAttachmentId);
            } else {
                $hadWarnings = true;
            }
        } else {
            $hadWarnings = true;
            $this->log('warning', $joomlaId, $wpId, 'Producto sin product_full_image en Joomla.');
        }

        $galleryAttachmentIds = [];
        foreach ($galleryFiles as $file) {
            if (trim($file) === '') {
                continue;
            }

            $attachmentId = $this->sideloadImage($file, $wpId, $joomlaId);
            if ($attachmentId !== null) {
                $galleryAttachmentIds[] = $attachmentId;
            } else {
                $hadWarnings = true;
            }
        }

        if ($galleryAttachmentIds !== []) {
            update_post_meta($wpId, self::META_GALLERY, implode(',', $galleryAttachmentIds));
        }

        if ($mainAttachmentId === null && $galleryAttachmentIds === []) {
            $this->log('error', $joomlaId, $wpId, 'No se pudo importar ninguna imagen para el producto.');
            return 'error';
        }

        if ($hadWarnings) {
            return 'warning';
        }

        $this->log('success', $joomlaId, $wpId, sprintf('Imagenes importadas (1 principal + %d galeria).', count($galleryAttachmentIds)));
        return 'success';
    }

    /**
     * Sideloadea un archivo desde la ruta de Joomla al media library.
     * Loggea internamente en caso de fallo.
     */
    private function sideloadImage(string $relativePath, int $wpId, int $joomlaId): ?int
    {
        $sourcePath = $this->resolveSourcePath($relativePath);

        if ($sourcePath === null) {
            $attempted = $this->resolvedBaseDir !== null
                ? $this->resolvedBaseDir . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\')
                : $relativePath;
            $this->log(
                'warning',
                $joomlaId,
                $wpId,
                sprintf('Archivo no encontrado o fuera del basedir: %s', $attempted)
            );
            return null;
        }

        $tempPath = wp_tempnam(basename($sourcePath));
        if ($tempPath === '' || !@copy($sourcePath, $tempPath)) {
            if ($tempPath !== '') {
                @unlink($tempPath);
            }
            $this->log('warning', $joomlaId, $wpId, sprintf('No se pudo copiar el archivo a temporal: %s', $sourcePath));
            return null;
        }

        $fileArray = [
            'name'     => basename($sourcePath),
            'tmp_name' => $tempPath,
        ];

        $attachmentId = media_handle_sideload($fileArray, $wpId);

        if ($attachmentId instanceof WP_Error) {
            @unlink($tempPath);
            $this->log(
                'warning',
                $joomlaId,
                $wpId,
                sprintf('media_handle_sideload fallo (%s): %s', basename($sourcePath), $attachmentId->get_error_message())
            );
            return null;
        }

        update_post_meta((int) $attachmentId, self::META_ATTACHMENT_TAG, 1);

        return (int) $attachmentId;
    }

    /**
     * Lee la ruta base configurada y la resuelve via realpath.
     */
    private function resolveBaseDir(): ?string
    {
        $base = (string) get_option(self::OPTION_IMAGES_PATH, '');
        if ($base === '') {
            return null;
        }

        $real = realpath($base);

        return is_string($real) && $real !== '' ? $real : null;
    }

    /**
     * Resuelve la ruta absoluta de un archivo relativo, garantizando que
     * permanece dentro del basedir configurado.
     */
    private function resolveSourcePath(string $relative): ?string
    {
        if ($this->resolvedBaseDir === null || $relative === '') {
            return null;
        }

        $candidate = $this->resolvedBaseDir . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
        $real      = realpath($candidate);

        if ($real === false || $real === '') {
            return null;
        }

        if (strpos($real, $this->resolvedBaseDir) !== 0) {
            return null;
        }

        if (!is_readable($real)) {
            return null;
        }

        return $real;
    }

    /**
     * Carga las dependencias de WP necesarias para media_handle_sideload.
     */
    private function loadMediaDependencies(): void
    {
        if (function_exists('media_handle_sideload')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    /**
     * Devuelve los IDs WP de productos a procesar en este batch.
     *
     * @return array<int, int>
     */
    private function fetchProductBatch(int $offset, int $limit): array
    {
        global $wpdb;

        $offset = max(0, $offset);
        $limit  = max(1, $limit);

        $sql = $wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type = %s
             ORDER BY pm.post_id ASC
             LIMIT %d OFFSET %d",
            self::META_JOOMLA_PRODUCT_ID,
            'product',
            $limit,
            $offset
        );

        $ids = $wpdb->get_col($sql);

        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * Recupera product_full_image para un set de joomla product_id.
     *
     * @param array<int, int> $joomlaIds
     * @return array<int, string>  joomla_id => filename
     */
    private function fetchMainImages(array $joomlaIds): array
    {
        if ($joomlaIds === []) {
            return [];
        }

        $prefix       = $this->connector->getTablePrefix();
        $placeholders = implode(', ', array_fill(0, count($joomlaIds), '?'));
        $params       = [];
        foreach ($joomlaIds as $i => $id) {
            $params[$i] = (int) $id;
        }

        $rows = $this->connector->select(
            "SELECT product_id, product_full_image
             FROM {$prefix}jshopping_products
             WHERE product_id IN ({$placeholders})",
            $params
        );

        $map = [];
        foreach ($rows as $row) {
            $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $img = isset($row['product_full_image']) ? trim((string) $row['product_full_image']) : '';
            if ($pid > 0) {
                $map[$pid] = $img;
            }
        }

        return $map;
    }

    /**
     * Recupera la galeria adicional para un set de joomla product_id.
     *
     * @param array<int, int> $joomlaIds
     * @return array<int, array<int, string>>  joomla_id => [filename, ...]
     */
    private function fetchGalleryImages(array $joomlaIds): array
    {
        if ($joomlaIds === []) {
            return [];
        }

        $prefix       = $this->connector->getTablePrefix();
        $placeholders = implode(', ', array_fill(0, count($joomlaIds), '?'));
        $params       = [];
        foreach ($joomlaIds as $i => $id) {
            $params[$i] = (int) $id;
        }

        $rows = $this->connector->select(
            "SELECT product_id, image_name, ordering
             FROM {$prefix}jshopping_products_images
             WHERE product_id IN ({$placeholders})
             ORDER BY product_id ASC, ordering ASC",
            $params
        );

        $map = [];
        foreach ($rows as $row) {
            $pid  = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $name = isset($row['image_name']) ? trim((string) $row['image_name']) : '';
            if ($pid > 0 && $name !== '') {
                $map[$pid][] = $name;
            }
        }

        return $map;
    }
}
