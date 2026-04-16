<?php
/**
 * Migrador de categorias JoomShopping -> taxonomia product_cat de WooCommerce.
 *
 * @package PatronatoMigrator\Migrators
 */

declare(strict_types=1);

namespace PatronatoMigrator\Migrators;

use Throwable;
use WP_Error;
use WP_Term;

defined('ABSPATH') || exit;

/**
 * Migra las 316 categorias de mjax8_jshopping_categories preservando la
 * jerarquia padre-hijo. Idempotente: re-ejecutar no genera duplicados.
 */
final class CategoryMigrator extends AbstractMigrator
{
    private const TAXONOMY      = 'product_cat';
    private const META_JOOMLA_ID = 'joomla_category_id';

    /**
     * Mapeo en memoria joomla_category_id => wp_term_id, construido a partir
     * de los terminos existentes y actualizado con cada insercion del batch.
     *
     * @var array<int, int>
     */
    private array $mapping = [];

    protected function getModuleName(): string
    {
        return 'categories';
    }

    public function getTotalCount(): int
    {
        $prefix = $this->connector->getTablePrefix();

        $value = $this->connector->selectValue(
            "SELECT COUNT(*) FROM {$prefix}jshopping_categories WHERE category_publish = 1"
        );

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array{processed:int,errors:int,skipped:int}
     */
    protected function processBatch(int $offset, int $limit): array
    {
        $this->mapping = $this->buildExistingMapping();

        $prefix = $this->connector->getTablePrefix();

        $rows = $this->connector->select(
            "SELECT category_id, category_parent_id, category_publish,
                    `name_es-ES`     AS name_es,
                    `name_en-GB`     AS name_en,
                    `alias_es-ES`    AS alias_es,
                    `alias_en-GB`    AS alias_en
             FROM {$prefix}jshopping_categories
             WHERE category_publish = 1
             ORDER BY category_parent_id ASC, category_id ASC
             LIMIT :limit OFFSET :offset",
            [
                'limit'  => $limit,
                'offset' => $offset,
            ]
        );

        $processed = 0;
        $errors    = 0;
        $skipped   = 0;

        foreach ($rows as $row) {
            $joomlaId = isset($row['category_id']) ? (int) $row['category_id'] : 0;

            if ($joomlaId <= 0) {
                $errors++;
                $this->log('error', null, null, 'Categoria origen sin category_id valido.');
                continue;
            }

            if (isset($this->mapping[$joomlaId])) {
                $skipped++;
                $this->log(
                    'skipped',
                    $joomlaId,
                    $this->mapping[$joomlaId],
                    'Categoria ya migrada previamente.'
                );
                continue;
            }

            try {
                $insertedTermId = $this->insertCategory($row);
            } catch (Throwable $e) {
                $errors++;
                $this->log('error', $joomlaId, null, sprintf('Excepcion al insertar: %s', $e->getMessage()));
                continue;
            }

            if ($insertedTermId === null) {
                $errors++;
                continue;
            }

            $this->mapping[$joomlaId] = $insertedTermId;
            $processed++;
            $this->log('success', $joomlaId, $insertedTermId, 'Categoria migrada.');
        }

        return [
            'processed' => $processed,
            'errors'    => $errors,
            'skipped'   => $skipped,
        ];
    }

    protected function rollbackModule(): void
    {
        $terms = get_terms([
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'fields'     => 'ids',
            'meta_query' => [
                [
                    'key'     => self::META_JOOMLA_ID,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return;
        }

        // Ordenar por nivel de jerarquia descendente: hojas primero para evitar
        // promover huerfanos durante el borrado.
        usort($terms, static function ($a, $b) {
            $term_a = get_term((int) $a, self::TAXONOMY);
            $term_b = get_term((int) $b, self::TAXONOMY);

            $depth_a = $term_a instanceof WP_Term ? self::countAncestors((int) $term_a->term_id) : 0;
            $depth_b = $term_b instanceof WP_Term ? self::countAncestors((int) $term_b->term_id) : 0;

            return $depth_b <=> $depth_a;
        });

        foreach ($terms as $term_id) {
            wp_delete_term((int) $term_id, self::TAXONOMY);
        }
    }

    /**
     * Inserta una categoria en WP, asignando parent y meta de origen.
     *
     * @param array<string, mixed> $row Fila de mjax8_jshopping_categories.
     */
    private function insertCategory(array $row): ?int
    {
        $joomlaId = (int) $row['category_id'];
        $parentJoomlaId = isset($row['category_parent_id']) ? (int) $row['category_parent_id'] : 0;

        $name = $this->resolveName($row, $joomlaId);
        $slug = $this->resolveSlug($row, $name);

        $parentWpId = 0;
        if ($parentJoomlaId > 0 && isset($this->mapping[$parentJoomlaId])) {
            $parentWpId = (int) $this->mapping[$parentJoomlaId];
        } elseif ($parentJoomlaId > 0) {
            $this->log(
                'warning',
                $joomlaId,
                null,
                sprintf('Parent %d no resuelto en el mapeo local; se inserta como raiz.', $parentJoomlaId)
            );
        }

        $args = ['slug' => $slug];
        if ($parentWpId > 0) {
            $args['parent'] = $parentWpId;
        }

        $result = wp_insert_term($name, self::TAXONOMY, $args);

        if ($result instanceof WP_Error) {
            // Posible colision de slug con termino preexistente sin meta de origen.
            $existingTermId = $this->resolveExistingTermBySlug($slug);
            if ($existingTermId !== null) {
                update_term_meta($existingTermId, self::META_JOOMLA_ID, $joomlaId);
                $this->log(
                    'warning',
                    $joomlaId,
                    $existingTermId,
                    sprintf('Slug "%s" ya existia; se enlazo el termino existente.', $slug)
                );

                return $existingTermId;
            }

            $this->log(
                'error',
                $joomlaId,
                null,
                sprintf('wp_insert_term fallo: %s', $result->get_error_message())
            );

            return null;
        }

        $termId = isset($result['term_id']) ? (int) $result['term_id'] : 0;
        if ($termId <= 0) {
            $this->log('error', $joomlaId, null, 'wp_insert_term devolvio term_id invalido.');
            return null;
        }

        update_term_meta($termId, self::META_JOOMLA_ID, $joomlaId);

        return $termId;
    }

    /**
     * Construye el mapeo joomla_id => wp_term_id desde los terminos existentes.
     *
     * @return array<int, int>
     */
    private function buildExistingMapping(): array
    {
        $mapping = [];

        $terms = get_terms([
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'     => self::META_JOOMLA_ID,
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

            $joomlaId = (int) get_term_meta($term->term_id, self::META_JOOMLA_ID, true);
            if ($joomlaId > 0) {
                $mapping[$joomlaId] = (int) $term->term_id;
            }
        }

        return $mapping;
    }

    /**
     * Devuelve el termino existente con ese slug si lo hay, o null.
     */
    private function resolveExistingTermBySlug(string $slug): ?int
    {
        $term = get_term_by('slug', $slug, self::TAXONOMY);

        return $term instanceof WP_Term ? (int) $term->term_id : null;
    }

    /**
     * Aplica la cadena de fallback de nombre: name_es -> name_en -> placeholder.
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
                return $candidate;
            }
        }

        $this->log(
            'warning',
            $joomlaId,
            null,
            'Nombre vacio en es-ES y en-GB; se usa placeholder.'
        );

        return sprintf('Sin nombre %d', $joomlaId);
    }

    /**
     * Aplica la cadena de fallback de slug: alias_es -> alias_en -> sanitize_title(name).
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

        return $fallback !== '' ? $fallback : 'categoria-' . md5($name);
    }

    /**
     * Cuenta ancestros de un termino para ordenar borrado por profundidad.
     */
    private static function countAncestors(int $termId): int
    {
        $ancestors = get_ancestors($termId, self::TAXONOMY, 'taxonomy');

        return is_array($ancestors) ? count($ancestors) : 0;
    }
}
