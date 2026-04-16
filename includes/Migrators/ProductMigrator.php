<?php
/**
 * Migrador de productos JoomShopping -> WooCommerce.
 *
 * @package PatronatoMigrator\Migrators
 */

declare(strict_types=1);

namespace PatronatoMigrator\Migrators;

use Throwable;
use WC_Product_Simple;
use WP_Error;
use WP_Term;

defined('ABSPATH') || exit;

/**
 * Migra los productos publicados de Joomla en batches a productos simples
 * de WooCommerce. Idempotente: detecta duplicados por SKU y por
 * _joomla_product_id antes de insertar.
 */
final class ProductMigrator extends AbstractMigrator
{
    private const META_JOOMLA_ID         = '_joomla_product_id';
    private const META_OBSERVACION       = '_observacion';
    private const META_OPCION            = '_opcion';
    private const META_JOOMLA_CAT_ID     = 'joomla_category_id';
    private const TAXONOMY_PRODUCT_CAT   = 'product_cat';
    private const ROLLBACK_BATCH_SIZE    = 200;
    private const SKU_FALLBACK_PREFIX    = 'JS-';

    /**
     * Mapeo joomla_category_id => wp_term_id, construido a partir de los
     * terminos existentes con la meta correspondiente.
     *
     * @var array<int, int>
     */
    private array $categoryMapping = [];

    protected function getModuleName(): string
    {
        return 'products';
    }

    public function getTotalCount(): int
    {
        $prefix = $this->connector->getTablePrefix();

        $value = $this->connector->selectValue(
            "SELECT COUNT(*) FROM {$prefix}jshopping_products WHERE product_publish = 1"
        );

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array{processed:int,errors:int,skipped:int}
     */
    protected function processBatch(int $offset, int $limit): array
    {
        $this->categoryMapping = $this->buildCategoryMapping();

        $prefix = $this->connector->getTablePrefix();

        $rows = $this->connector->select(
            "SELECT product_id, product_publish, product_ean, product_price,
                    product_old_price, product_quantity, unlimited, product_weight,
                    observacion, opcion,
                    `name_es-ES`              AS name_es,
                    `name_en-GB`              AS name_en,
                    `alias_es-ES`             AS alias_es,
                    `alias_en-GB`             AS alias_en,
                    `description_es-ES`       AS description_es,
                    `description_en-GB`       AS description_en,
                    `short_description_es-ES` AS short_description_es,
                    `short_description_en-GB` AS short_description_en
             FROM {$prefix}jshopping_products
             WHERE product_publish = 1
             ORDER BY product_id ASC
             LIMIT :limit OFFSET :offset",
            [
                'limit'  => $limit,
                'offset' => $offset,
            ]
        );

        $productIds = array_map(static fn (array $row): int => (int) ($row['product_id'] ?? 0), $rows);
        $productIds = array_values(array_filter($productIds, static fn (int $id): bool => $id > 0));

        $categoriesByProduct = $this->fetchCategoriesForProducts($productIds);

        $processed = 0;
        $errors    = 0;
        $skipped   = 0;

        foreach ($rows as $row) {
            $joomlaId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            if ($joomlaId <= 0) {
                $errors++;
                $this->log('error', null, null, 'Producto origen sin product_id valido.');
                continue;
            }

            try {
                $outcome = $this->processSingleProduct($row, $categoriesByProduct[$joomlaId] ?? []);
            } catch (Throwable $e) {
                $errors++;
                $this->log('error', $joomlaId, null, sprintf('Excepcion al insertar: %s', $e->getMessage()));
                continue;
            }

            switch ($outcome['status']) {
                case 'success':
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

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }

        return [
            'processed' => $processed,
            'errors'    => $errors,
            'skipped'   => $skipped,
        ];
    }

    protected function rollbackModule(): void
    {
        while (true) {
            $ids = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'any',
                'posts_per_page' => self::ROLLBACK_BATCH_SIZE,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [
                    [
                        'key'     => self::META_JOOMLA_ID,
                        'compare' => 'EXISTS',
                    ],
                ],
            ]);

            if (!is_array($ids) || $ids === []) {
                break;
            }

            foreach ($ids as $id) {
                wp_delete_post((int) $id, true);
            }
        }

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }
    }

    /**
     * Procesa un unico producto y devuelve su outcome.
     *
     * @param array<string, mixed> $row
     * @param array<int, int>      $joomlaCategoryIds
     * @return array{status:string, wp_id:?int}
     */
    private function processSingleProduct(array $row, array $joomlaCategoryIds): array
    {
        $joomlaId = (int) $row['product_id'];

        $existingByMeta = $this->findExistingByJoomlaId($joomlaId);
        if ($existingByMeta !== null) {
            $this->log('skipped', $joomlaId, $existingByMeta, 'Producto ya migrado previamente (meta).');
            return ['status' => 'skipped', 'wp_id' => $existingByMeta];
        }

        $sku = $this->resolveSku($row, $joomlaId);

        $existingBySku = wc_get_product_id_by_sku($sku);
        if (is_numeric($existingBySku) && (int) $existingBySku > 0) {
            $existingId = (int) $existingBySku;
            update_post_meta($existingId, self::META_JOOMLA_ID, $joomlaId);
            $this->log('skipped', $joomlaId, $existingId, sprintf('SKU "%s" ya existe; se enlazo el producto existente.', $sku));
            return ['status' => 'skipped', 'wp_id' => $existingId];
        }

        $name = $this->resolveName($row, $joomlaId);
        $slug = $this->resolveSlug($row, $name);

        $description      = $this->resolveDescription($row, 'description');
        $shortDescription = $this->resolveDescription($row, 'short_description');

        $regularPrice = $this->resolvePrice($row['product_price'] ?? null);
        $oldPrice     = $this->resolvePrice($row['product_old_price'] ?? null);
        $weight       = $this->resolvePrice($row['product_weight'] ?? null);

        $unlimited = isset($row['unlimited']) ? (int) $row['unlimited'] : 0;
        $quantity  = isset($row['product_quantity']) ? (int) $row['product_quantity'] : 0;

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_slug($slug);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_description($description);
        $product->set_short_description($shortDescription);
        $product->set_sku($sku);

        if ($regularPrice !== null) {
            $product->set_regular_price((string) $regularPrice);
        }
        if ($oldPrice !== null && $oldPrice > 0 && $regularPrice !== null && $oldPrice < $regularPrice) {
            // En Joomla product_price es el precio actual y product_old_price el anterior.
            // En WooCommerce, sale_price es el precio rebajado: si old < actual, no aplica.
            $product->set_sale_price((string) $oldPrice);
        }

        if ($weight !== null && $weight > 0) {
            $product->set_weight((string) $weight);
        }

        if ($unlimited === 1) {
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        } else {
            $product->set_manage_stock(true);
            $product->set_stock_quantity(max(0, $quantity));
            $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
        }

        $wpCategoryIds = $this->resolveWpCategoryIds($joomlaCategoryIds, $joomlaId);
        if ($wpCategoryIds !== []) {
            $product->set_category_ids($wpCategoryIds);
        }

        $productId = $product->save();
        if (!is_int($productId) || $productId <= 0) {
            $this->log('error', $joomlaId, null, 'WC_Product::save() devolvio id invalido.');
            return ['status' => 'error', 'wp_id' => null];
        }

        update_post_meta($productId, self::META_JOOMLA_ID, $joomlaId);

        $observacion = isset($row['observacion']) ? sanitize_textarea_field((string) $row['observacion']) : '';
        if ($observacion !== '') {
            update_post_meta($productId, self::META_OBSERVACION, $observacion);
        }

        $opcion = isset($row['opcion']) ? sanitize_text_field((string) $row['opcion']) : '';
        if ($opcion !== '') {
            update_post_meta($productId, self::META_OPCION, $opcion);
        }

        $this->log('success', $joomlaId, $productId, 'Producto migrado.');

        return ['status' => 'success', 'wp_id' => $productId];
    }

    /**
     * Mapea los joomla_category_id del producto a wp_term_id, registrando
     * un warning por cada categoria no resuelta en el mapeo local.
     *
     * @param array<int, int> $joomlaCategoryIds
     * @return array<int, int>
     */
    private function resolveWpCategoryIds(array $joomlaCategoryIds, int $joomlaId): array
    {
        $wpIds   = [];
        $missing = [];

        foreach ($joomlaCategoryIds as $jcid) {
            if ($jcid <= 0) {
                continue;
            }

            if (isset($this->categoryMapping[$jcid])) {
                $wpIds[] = $this->categoryMapping[$jcid];
            } else {
                $missing[] = $jcid;
            }
        }

        if ($missing !== []) {
            $this->log(
                'warning',
                $joomlaId,
                null,
                sprintf('Categorias Joomla no encontradas en el mapeo: %s.', implode(', ', $missing))
            );
        }

        return array_values(array_unique($wpIds));
    }

    /**
     * Recupera el WP product_id existente con la meta de origen.
     */
    private function findExistingByJoomlaId(int $joomlaId): ?int
    {
        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => self::META_JOOMLA_ID,
                    'value'   => $joomlaId,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        if (!is_array($ids) || $ids === []) {
            return null;
        }

        return (int) $ids[0];
    }

    /**
     * Lee las relaciones producto-categoria para el batch en una sola consulta.
     *
     * @param array<int, int> $productIds
     * @return array<int, array<int, int>>  Mapa product_id => [category_id, ...].
     */
    private function fetchCategoriesForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $prefix       = $this->connector->getTablePrefix();
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $params       = [];
        foreach ($productIds as $i => $id) {
            $params[$i] = (int) $id;
        }

        $rows = $this->connector->select(
            "SELECT product_id, category_id
             FROM {$prefix}jshopping_products_to_categories
             WHERE product_id IN ({$placeholders})",
            $params
        );

        $map = [];
        foreach ($rows as $row) {
            $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $cid = isset($row['category_id']) ? (int) $row['category_id'] : 0;
            if ($pid > 0 && $cid > 0) {
                $map[$pid][] = $cid;
            }
        }

        return $map;
    }

    /**
     * Carga el mapeo joomla_category_id => wp_term_id desde los terminos
     * de product_cat con meta joomla_category_id.
     *
     * @return array<int, int>
     */
    private function buildCategoryMapping(): array
    {
        $mapping = [];

        $terms = get_terms([
            'taxonomy'   => self::TAXONOMY_PRODUCT_CAT,
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'     => self::META_JOOMLA_CAT_ID,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return $mapping;
        }

        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            $jcid = (int) get_term_meta($term->term_id, self::META_JOOMLA_CAT_ID, true);
            if ($jcid > 0) {
                $mapping[$jcid] = (int) $term->term_id;
            }
        }

        return $mapping;
    }

    /**
     * Resuelve el SKU: usa product_ean si esta seteado, en su defecto un
     * SKU derivado del joomla product_id para preservar idempotencia.
     *
     * @param array<string, mixed> $row
     */
    private function resolveSku(array $row, int $joomlaId): string
    {
        $ean = isset($row['product_ean']) ? trim((string) $row['product_ean']) : '';

        if ($ean !== '') {
            return sanitize_text_field($ean);
        }

        return self::SKU_FALLBACK_PREFIX . $joomlaId;
    }

    /**
     * Aplica la cadena de fallback del nombre.
     *
     * @param array<string, mixed> $row
     */
    private function resolveName(array $row, int $joomlaId): string
    {
        $candidates = [
            isset($row['name_es']) ? trim((string) $row['name_es']) : '',
            isset($row['name_en']) ? trim((string) $row['name_en']) : '',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return sanitize_text_field($candidate);
            }
        }

        $this->log('warning', $joomlaId, null, 'Nombre vacio en es-ES y en-GB; se usa placeholder.');

        return sprintf('Sin nombre %d', $joomlaId);
    }

    /**
     * Aplica la cadena de fallback del slug.
     *
     * @param array<string, mixed> $row
     */
    private function resolveSlug(array $row, string $name): string
    {
        $candidates = [
            isset($row['alias_es']) ? trim((string) $row['alias_es']) : '',
            isset($row['alias_en']) ? trim((string) $row['alias_en']) : '',
        ];

        foreach ($candidates as $candidate) {
            $sanitized = sanitize_title($candidate);
            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        $fallback = sanitize_title($name);

        return $fallback !== '' ? $fallback : 'producto-' . md5($name);
    }

    /**
     * Aplica la cadena de fallback de descripcion para 'description' o
     * 'short_description'. Preserva HTML permitido por wp_kses_post.
     *
     * @param array<string, mixed> $row
     * @param 'description'|'short_description' $kind
     */
    private function resolveDescription(array $row, string $kind): string
    {
        $keyEs = $kind . '_es';
        $keyEn = $kind . '_en';

        $candidates = [
            isset($row[$keyEs]) ? (string) $row[$keyEs] : '',
            isset($row[$keyEn]) ? (string) $row[$keyEn] : '',
        ];

        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed !== '') {
                return wp_kses_post($trimmed);
            }
        }

        return '';
    }

    /**
     * Convierte un valor numerico (string o int) a float seguro.
     *
     * @param mixed $value
     */
    private function resolvePrice($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
