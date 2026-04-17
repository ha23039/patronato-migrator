<?php
/**
 * Generador de redirects 301 desde URLs legacy de Joomla -> WooCommerce.
 *
 * @package PatronatoMigrator\Migrators
 */

declare(strict_types=1);

namespace PatronatoMigrator\Migrators;

use RuntimeException;
use Throwable;

defined('ABSPATH') || exit;

/**
 * Construye dos artefactos descargables:
 *   - patronato-redirects.htaccess  (reglas mod_rewrite con R=301,L)
 *   - patronato-redirects.sql       (INSERTs para el plugin Redirection)
 *
 * Trabaja sobre los datos ya migrados: necesita los slugs de WC (productos
 * y categorias) para construir las URLs destino. La generacion es one-shot
 * por batch: el cursor pasa de 0 al total en una sola peticion AJAX.
 */
final class RedirectMigrator extends AbstractMigrator
{
    public const FILE_HTACCESS = 'patronato-redirects.htaccess';
    public const FILE_SQL      = 'patronato-redirects.sql';

    private const META_JOOMLA_PRODUCT_ID  = '_joomla_product_id';
    private const META_JOOMLA_CATEGORY_ID = 'joomla_category_id';

    protected function getModuleName(): string
    {
        return 'redirects';
    }

    public function getTotalCount(): int
    {
        return $this->countCategories() + $this->countProducts();
    }

    /**
     * @return array{processed:int,errors:int,skipped:int}
     */
    protected function processBatch(int $offset, int $limit): array
    {
        if ($offset > 0) {
            return ['processed' => 0, 'errors' => 0, 'skipped' => 0];
        }

        try {
            [$htaccessPath, $sqlPath] = $this->resolveOutputPaths();
        } catch (Throwable $e) {
            $this->log('error', null, null, sprintf('No se pudo preparar el directorio de exports: %s', $e->getMessage()));
            return ['processed' => 0, 'errors' => 1, 'skipped' => 0];
        }

        $htaccess = @fopen($htaccessPath, 'w');
        $sql      = @fopen($sqlPath, 'w');

        if (!is_resource($htaccess) || !is_resource($sql)) {
            if (is_resource($htaccess)) {
                fclose($htaccess);
            }
            if (is_resource($sql)) {
                fclose($sql);
            }
            $this->log('error', null, null, 'No se pudo abrir los archivos de redirects para escritura.');
            return ['processed' => 0, 'errors' => 1, 'skipped' => 0];
        }

        $written = 0;
        $errors  = 0;
        $skipped = 0;

        try {
            $this->writeHtaccessHeader($htaccess);
            $this->writeSqlHeader($sql);

            $catStats = $this->writeCategoryRedirects($htaccess, $sql);
            $written += $catStats['written'];
            $errors  += $catStats['errors'];
            $skipped += $catStats['skipped'];

            $prodStats = $this->writeProductRedirects($htaccess, $sql);
            $written += $prodStats['written'];
            $errors  += $prodStats['errors'];
            $skipped += $prodStats['skipped'];

            $this->writeHtaccessFooter($htaccess, $written);
            $this->writeSqlFooter($sql, $written);
        } finally {
            fclose($htaccess);
            fclose($sql);
        }

        $this->log(
            'success',
            null,
            null,
            sprintf(
                'Generados %d redirects. Archivos: %s y %s.',
                $written,
                self::FILE_HTACCESS,
                self::FILE_SQL
            )
        );

        return [
            'processed' => $written,
            'errors'    => $errors,
            'skipped'   => $skipped,
        ];
    }

    protected function rollbackModule(): void
    {
        $dir = $this->getExportsDirOrNull();
        if ($dir === null) {
            return;
        }

        foreach ([self::FILE_HTACCESS, self::FILE_SQL] as $file) {
            $path = trailingslashit($dir) . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Devuelve la URL publica de los archivos generados, o null si no existen.
     *
     * @return array{htaccess:?string, sql:?string}
     */
    public function getDownloadUrls(): array
    {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return ['htaccess' => null, 'sql' => null];
        }

        $baseDir = trailingslashit($upload['basedir']) . 'patronato-migrator';
        $baseUrl = trailingslashit($upload['baseurl']) . 'patronato-migrator';

        $htaccess = is_file(trailingslashit($baseDir) . self::FILE_HTACCESS)
            ? trailingslashit($baseUrl) . self::FILE_HTACCESS
            : null;
        $sql = is_file(trailingslashit($baseDir) . self::FILE_SQL)
            ? trailingslashit($baseUrl) . self::FILE_SQL
            : null;

        return ['htaccess' => $htaccess, 'sql' => $sql];
    }

    private function countCategories(): int
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s",
            self::META_JOOMLA_CATEGORY_ID
        );

        return (int) $wpdb->get_var($sql);
    }

    private function countProducts(): int
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = %s",
            self::META_JOOMLA_PRODUCT_ID,
            'product',
            'publish'
        );

        return (int) $wpdb->get_var($sql);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveOutputPaths(): array
    {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            throw new RuntimeException((string) $upload['error']);
        }

        $dir = trailingslashit($upload['basedir']) . 'patronato-migrator';
        if (!wp_mkdir_p($dir)) {
            throw new RuntimeException('mkdir fallo');
        }

        return [
            trailingslashit($dir) . self::FILE_HTACCESS,
            trailingslashit($dir) . self::FILE_SQL,
        ];
    }

    private function getExportsDirOrNull(): ?string
    {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return null;
        }

        return trailingslashit($upload['basedir']) . 'patronato-migrator';
    }

    /**
     * @param resource $handle
     */
    private function writeHtaccessHeader($handle): void
    {
        fwrite($handle, "# Patronato Migrator - redirects 301 desde URLs legacy Joomla\n");
        fwrite($handle, "# Generado: " . gmdate('Y-m-d H:i:s') . " UTC\n");
        fwrite($handle, "# Pegar este bloque dentro del .htaccess raiz, antes de las reglas de WordPress.\n\n");
        fwrite($handle, "<IfModule mod_rewrite.c>\n");
        fwrite($handle, "RewriteEngine On\n\n");
    }

    /**
     * @param resource $handle
     */
    private function writeHtaccessFooter($handle, int $written): void
    {
        fwrite($handle, "\n</IfModule>\n");
        fwrite($handle, "# Total de reglas: {$written}\n");
    }

    /**
     * @param resource $handle
     */
    private function writeSqlHeader($handle): void
    {
        fwrite($handle, "-- Patronato Migrator - redirects 301 desde URLs legacy Joomla\n");
        fwrite($handle, "-- Generado: " . gmdate('Y-m-d H:i:s') . " UTC\n");
        fwrite($handle, "-- Compatible con plugin Redirection (https://wordpress.org/plugins/redirection/).\n");
        fwrite($handle, "-- Antes de ejecutar:\n");
        fwrite($handle, "--   1. Instalar y activar el plugin Redirection.\n");
        fwrite($handle, "--   2. Ajustar el prefijo de tabla (`wp_`) si difiere en tu instalacion.\n");
        fwrite($handle, "--   3. Ajustar group_id si quieres separar estos redirects en un grupo propio.\n\n");
    }

    /**
     * @param resource $handle
     */
    private function writeSqlFooter($handle, int $written): void
    {
        fwrite($handle, "\n-- Total de redirects: {$written}\n");
    }

    /**
     * @param resource $htaccess
     * @param resource $sql
     * @return array{written:int,errors:int,skipped:int}
     */
    private function writeCategoryRedirects($htaccess, $sql): array
    {
        global $wpdb;

        fwrite($htaccess, "# Categorias\n");
        fwrite($sql, "-- Categorias\n");

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tm.term_id, tm.meta_value AS joomla_id
                 FROM {$wpdb->termmeta} tm
                 WHERE tm.meta_key = %s",
                self::META_JOOMLA_CATEGORY_ID
            ),
            ARRAY_A
        );

        if (!is_array($rows) || $rows === []) {
            return ['written' => 0, 'errors' => 0, 'skipped' => 0];
        }

        $written = 0;
        $errors  = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $joomlaId = isset($row['joomla_id']) ? (int) $row['joomla_id'] : 0;
            $termId   = isset($row['term_id']) ? (int) $row['term_id'] : 0;

            if ($joomlaId <= 0 || $termId <= 0) {
                $skipped++;
                continue;
            }

            $link = get_term_link($termId, 'product_cat');
            if (is_wp_error($link) || !is_string($link) || $link === '') {
                $errors++;
                $this->log('warning', $joomlaId, $termId, 'No se pudo obtener permalink de categoria.');
                continue;
            }

            $targetPath = $this->relativePath($link);
            $sourceQs   = "option=com_jshopping&act=viewCat&category_id={$joomlaId}";

            $this->writeHtaccessRule($htaccess, $sourceQs, $targetPath);
            $this->writeSqlInsert($sql, '/index.php?' . $sourceQs, $targetPath);

            $written++;
        }

        fwrite($htaccess, "\n");
        fwrite($sql, "\n");

        return ['written' => $written, 'errors' => $errors, 'skipped' => $skipped];
    }

    /**
     * @param resource $htaccess
     * @param resource $sql
     * @return array{written:int,errors:int,skipped:int}
     */
    private function writeProductRedirects($htaccess, $sql): array
    {
        global $wpdb;

        fwrite($htaccess, "# Productos\n");
        fwrite($sql, "-- Productos\n");

        $batch  = 500;
        $offset = 0;

        $written = 0;
        $errors  = 0;
        $skipped = 0;

        while (true) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pm.post_id, pm.meta_value AS joomla_id
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = %s
                       AND p.post_type = %s
                       AND p.post_status = %s
                     ORDER BY pm.post_id ASC
                     LIMIT %d OFFSET %d",
                    self::META_JOOMLA_PRODUCT_ID,
                    'product',
                    'publish',
                    $batch,
                    $offset
                ),
                ARRAY_A
            );

            if (!is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $joomlaId = isset($row['joomla_id']) ? (int) $row['joomla_id'] : 0;
                $postId   = isset($row['post_id']) ? (int) $row['post_id'] : 0;

                if ($joomlaId <= 0 || $postId <= 0) {
                    $skipped++;
                    continue;
                }

                $link = get_permalink($postId);
                if (!is_string($link) || $link === '') {
                    $errors++;
                    continue;
                }

                $targetPath = $this->relativePath($link);
                $sourceQs   = "option=com_jshopping&act=viewProduct&productId={$joomlaId}";

                $this->writeHtaccessRule($htaccess, $sourceQs, $targetPath);
                $this->writeSqlInsert($sql, '/index.php?' . $sourceQs, $targetPath);

                $written++;
            }

            $offset += $batch;

            if (count($rows) < $batch) {
                break;
            }
        }

        return ['written' => $written, 'errors' => $errors, 'skipped' => $skipped];
    }

    /**
     * Escribe una regla mod_rewrite por query string exacto.
     *
     * @param resource $handle
     */
    private function writeHtaccessRule($handle, string $queryString, string $targetPath): void
    {
        fwrite(
            $handle,
            sprintf(
                "RewriteCond %%{QUERY_STRING} ^%s$ [NC]\nRewriteRule ^index\\.php$ %s? [R=301,L]\n",
                $this->escapeForApacheRegex($queryString),
                $targetPath
            )
        );
    }

    /**
     * Escribe un INSERT para la tabla wp_redirection_items del plugin Redirection.
     *
     * @param resource $handle
     */
    private function writeSqlInsert($handle, string $sourceUrl, string $targetPath): void
    {
        $source = $this->escapeSql($sourceUrl);
        $target = $this->escapeSql($targetPath);

        fwrite(
            $handle,
            sprintf(
                "INSERT INTO `wp_redirection_items` (`url`, `regex`, `match_url`, `action_data`, `action_type`, `action_code`, `group_id`, `status`, `position`) VALUES ('%s', 0, '%s', '%s', 'url', 301, 1, 'enabled', 0);\n",
                $source,
                $source,
                $target
            )
        );
    }

    /**
     * Convierte una URL absoluta del sitio en su path relativo (incluye query).
     */
    private function relativePath(string $url): string
    {
        $parts = wp_parse_url($url);
        $path  = isset($parts['path']) ? (string) $parts['path'] : '/';
        if ($path === '') {
            $path = '/';
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        return $path;
    }

    /**
     * Escapa caracteres especiales de regex Apache para coincidencia literal.
     */
    private function escapeForApacheRegex(string $value): string
    {
        return preg_replace('/([\\.\\\\\\^\\$\\*\\+\\?\\(\\)\\[\\]\\{\\}\\|\\/])/', '\\\\$1', $value) ?? $value;
    }

    /**
     * Escapa una cadena para inclusion en SQL entre comillas simples.
     */
    private function escapeSql(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
