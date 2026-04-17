<?php
/**
 * Vista: Visor de log de migracion.
 *
 * Renderiza tres secciones:
 *   1. Descargas: exportacion del log a CSV y archivos generados por
 *      RedirectMigrator (.htaccess y SQL para plugin Redirection).
 *   2. Filtros: selector de modulo y de estado.
 *   3. Resultados: tabla con paginacion incremental ("Cargar mas").
 *
 * Variables esperadas en el scope:
 *   - $modules        (array<int,string>) Slugs de modulos validos.
 *   - $statuses       (array<int,string>) Estados validos del log.
 *   - $export_url_base(string)            URL base de admin-post.php.
 *   - $download_urls  (array<string,?string>) keys: 'htaccess', 'sql'.
 *
 * @package PatronatoMigrator
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$pm_modules        = isset( $modules ) && is_array( $modules ) ? array_values( $modules ) : array();
$pm_statuses       = isset( $statuses ) && is_array( $statuses ) ? array_values( $statuses ) : array();
$pm_export_url     = isset( $export_url_base ) ? (string) $export_url_base : admin_url( 'admin-post.php' );
$pm_download_urls  = isset( $download_urls ) && is_array( $download_urls ) ? $download_urls : array();
$pm_htaccess_ready = isset( $pm_download_urls['htaccess'] ) && '' !== (string) $pm_download_urls['htaccess'];
$pm_sql_ready      = isset( $pm_download_urls['sql'] ) && '' !== (string) $pm_download_urls['sql'];

/**
 * Etiquetas legibles para slugs de modulo. Si llegara un slug no contemplado
 * se muestra el slug crudo, evitando romper la vista.
 *
 * @var array<string, string>
 */
$pm_module_labels = array(
	'categories' => __( 'Categorias', 'patronato-migrator' ),
	'products'   => __( 'Productos', 'patronato-migrator' ),
	'images'     => __( 'Imagenes', 'patronato-migrator' ),
	'customers'  => __( 'Usuarios', 'patronato-migrator' ),
	'orders'     => __( 'Pedidos', 'patronato-migrator' ),
	'redirects'  => __( 'Redirects', 'patronato-migrator' ),
);

/**
 * Etiquetas legibles para los estados del log.
 *
 * @var array<string, string>
 */
$pm_status_labels = array(
	'success' => __( 'Exito', 'patronato-migrator' ),
	'warning' => __( 'Advertencia', 'patronato-migrator' ),
	'error'   => __( 'Error', 'patronato-migrator' ),
	'skipped' => __( 'Omitido', 'patronato-migrator' ),
);

// URL del CSV sin filtro de modulo. JS la actualiza al cambiar el selector.
$pm_export_csv_url = wp_nonce_url(
	add_query_arg( array( 'action' => 'pm_export_log' ), $pm_export_url ),
	'pm_export_log'
);

if ( $pm_htaccess_ready ) {
	$pm_htaccess_url = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'pm_download_redirect',
				'file'   => 'htaccess',
			),
			$pm_export_url
		),
		'pm_download_redirect'
	);
}

if ( $pm_sql_ready ) {
	$pm_sql_url = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'pm_download_redirect',
				'file'   => 'sql',
			),
			$pm_export_url
		),
		'pm_download_redirect'
	);
}
?>
<div class="wrap pm-wrap">
	<h1><?php esc_html_e( 'Log de migracion', 'patronato-migrator' ); ?></h1>

	<p class="pm-lead">
		<?php esc_html_e( 'Consulta el historial de operaciones registradas durante la migracion. Filtra por modulo y estado, exporta el log a CSV o descarga los archivos de redirects.', 'patronato-migrator' ); ?>
	</p>

	<section class="pm-card pm-log-downloads" aria-labelledby="pm-log-downloads-title">
		<h2 id="pm-log-downloads-title"><?php esc_html_e( 'Descargas', 'patronato-migrator' ); ?></h2>

		<div class="pm-download-actions">
			<a
				class="pm-btn pm-btn-primary"
				id="pm-log-export-csv"
				data-pm-export-link
				data-pm-export-base="<?php echo esc_attr( $pm_export_csv_url ); ?>"
				href="<?php echo esc_url( $pm_export_csv_url ); ?>"
			>
				<?php esc_html_e( 'Exportar log a CSV', 'patronato-migrator' ); ?>
			</a>
		</div>

		<h3 class="pm-download-subtitle"><?php esc_html_e( 'Archivos de redirects', 'patronato-migrator' ); ?></h3>

		<?php if ( $pm_htaccess_ready || $pm_sql_ready ) : ?>
			<div class="pm-download-actions">
				<?php if ( $pm_htaccess_ready ) : ?>
					<a
						class="pm-btn pm-btn-secondary"
						href="<?php echo esc_url( $pm_htaccess_url ); ?>"
					>
						<?php esc_html_e( 'Descargar .htaccess', 'patronato-migrator' ); ?>
					</a>
				<?php endif; ?>

				<?php if ( $pm_sql_ready ) : ?>
					<a
						class="pm-btn pm-btn-secondary"
						href="<?php echo esc_url( $pm_sql_url ); ?>"
					>
						<?php esc_html_e( 'Descargar SQL para Redirection', 'patronato-migrator' ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<p class="pm-help">
				<?php esc_html_e( 'Aun no se han generado los archivos de redirects. Ejecuta el modulo Redirects desde el dashboard.', 'patronato-migrator' ); ?>
			</p>
		<?php endif; ?>
	</section>

	<section class="pm-card pm-log-filters" aria-labelledby="pm-log-filters-title">
		<h2 id="pm-log-filters-title"><?php esc_html_e( 'Filtros', 'patronato-migrator' ); ?></h2>

		<div class="pm-filter-row">
			<div class="pm-field">
				<label for="pm-log-module"><?php esc_html_e( 'Modulo', 'patronato-migrator' ); ?></label>
				<select id="pm-log-module" name="pm-log-module">
					<option value=""><?php esc_html_e( 'Todos los modulos', 'patronato-migrator' ); ?></option>
					<?php foreach ( $pm_modules as $pm_module_slug ) :
						$pm_slug  = (string) $pm_module_slug;
						$pm_label = isset( $pm_module_labels[ $pm_slug ] ) ? $pm_module_labels[ $pm_slug ] : $pm_slug;
						?>
						<option value="<?php echo esc_attr( $pm_slug ); ?>">
							<?php echo esc_html( $pm_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="pm-field">
				<label for="pm-log-status"><?php esc_html_e( 'Estado', 'patronato-migrator' ); ?></label>
				<select id="pm-log-status" name="pm-log-status">
					<option value=""><?php esc_html_e( 'Todos los estados', 'patronato-migrator' ); ?></option>
					<?php foreach ( $pm_statuses as $pm_status_slug ) :
						$pm_slug  = (string) $pm_status_slug;
						$pm_label = isset( $pm_status_labels[ $pm_slug ] ) ? $pm_status_labels[ $pm_slug ] : $pm_slug;
						?>
						<option value="<?php echo esc_attr( $pm_slug ); ?>">
							<?php echo esc_html( $pm_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="pm-filter-actions">
				<button type="button" id="pm-log-apply" class="pm-btn pm-btn-primary">
					<?php esc_html_e( 'Aplicar filtros', 'patronato-migrator' ); ?>
				</button>
			</div>
		</div>
	</section>

	<section class="pm-card pm-log-results" aria-labelledby="pm-log-results-title">
		<h2 id="pm-log-results-title"><?php esc_html_e( 'Resultados', 'patronato-migrator' ); ?></h2>

		<div class="pm-log-table-wrap">
			<table id="pm-log-table" class="pm-log-table" role="grid">
				<thead>
					<tr>
						<th scope="col" class="pm-cell-id"><?php esc_html_e( 'ID', 'patronato-migrator' ); ?></th>
						<th scope="col" class="pm-cell-date"><?php esc_html_e( 'Fecha', 'patronato-migrator' ); ?></th>
						<th scope="col" class="pm-cell-module"><?php esc_html_e( 'Modulo', 'patronato-migrator' ); ?></th>
						<th scope="col" class="pm-cell-status"><?php esc_html_e( 'Estado', 'patronato-migrator' ); ?></th>
						<th scope="col" class="pm-cell-joomla"><?php esc_html_e( 'Joomla ID', 'patronato-migrator' ); ?></th>
						<th scope="col" class="pm-cell-wp"><?php esc_html_e( 'WP ID', 'patronato-migrator' ); ?></th>
						<th scope="col" class="pm-cell-message"><?php esc_html_e( 'Mensaje', 'patronato-migrator' ); ?></th>
					</tr>
				</thead>
				<tbody data-pm-role="log-rows"></tbody>
			</table>
		</div>

		<div class="pm-log-table-footer">
			<p id="pm-log-status-text" class="pm-log-status-text" role="status" aria-live="polite">
				<?php esc_html_e( 'Cargando...', 'patronato-migrator' ); ?>
			</p>
			<button
				type="button"
				id="pm-log-load-more"
				class="pm-btn pm-btn-secondary"
				hidden
			>
				<?php esc_html_e( 'Cargar mas', 'patronato-migrator' ); ?>
			</button>
		</div>
	</section>
</div>
