<?php
/**
 * Vista: Dashboard del plugin Patronato Migrator.
 *
 * Lista las tarjetas de progreso de cada modulo de migracion. En el Sprint 2
 * solo el modulo "categories" esta habilitado; el resto se muestra como
 * pendiente del sprint correspondiente.
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

$pm_config_url       = admin_url( 'admin.php?page=patronato-migrator-config' );
$pm_credentials_set  = (string) get_option( 'pm_joomla_credentials', '' ) !== '';

/**
 * Definicion declarativa de las tarjetas de modulos. El orden refleja el
 * pipeline de migracion (categorias -> productos -> imagenes -> ...).
 *
 * @var array<int, array{
 *     module: string,
 *     label: string,
 *     description: string,
 *     enabled: bool,
 *     pending_sprint: string|null
 * }>
 */
$pm_modules = array(
	array(
		'module'         => 'categories',
		'label'          => __( 'Categorias', 'patronato-migrator' ),
		'description'    => __( '316 categorias de JoomShopping', 'patronato-migrator' ),
		'enabled'        => true,
		'pending_sprint' => null,
	),
	array(
		'module'         => 'products',
		'label'          => __( 'Productos', 'patronato-migrator' ),
		'description'    => __( '28,624 productos en lotes de 100', 'patronato-migrator' ),
		'enabled'        => true,
		'pending_sprint' => null,
	),
	array(
		'module'         => 'images',
		'label'          => __( 'Imagenes', 'patronato-migrator' ),
		'description'    => __( 'Imagenes principales y galeria, lotes de 50', 'patronato-migrator' ),
		'enabled'        => true,
		'pending_sprint' => null,
	),
	array(
		'module'         => 'customers',
		'label'          => __( 'Usuarios', 'patronato-migrator' ),
		'description'    => __( '92,595 usuarios activos en lotes de 200', 'patronato-migrator' ),
		'enabled'        => true,
		'pending_sprint' => null,
	),
	array(
		'module'         => 'orders',
		'label'          => __( 'Pedidos', 'patronato-migrator' ),
		'description'    => __( '35,497 pedidos historicos en lotes de 50', 'patronato-migrator' ),
		'enabled'        => true,
		'pending_sprint' => null,
	),
	array(
		'module'         => 'redirects',
		'label'          => __( 'Redirects', 'patronato-migrator' ),
		'description'    => __( 'Mapa de URLs legacy', 'patronato-migrator' ),
		'enabled'        => false,
		'pending_sprint' => __( 'Sprint 7', 'patronato-migrator' ),
	),
);
?>
<div class="wrap pm-wrap">
	<h1><?php esc_html_e( 'Patronato Migrator', 'patronato-migrator' ); ?></h1>

	<?php if ( ! $pm_credentials_set ) : ?>
		<div class="pm-notice pm-notice-warning" role="alert">
			<?php
			printf(
				/* translators: %s: enlace HTML a la pagina de configuracion. */
				wp_kses(
					/* translators: %s: enlace HTML a la pagina de configuracion. */
					__( 'Aun no hay credenciales de Joomla configuradas. %s antes de iniciar la migracion.', 'patronato-migrator' ),
					array( 'a' => array( 'href' => array() ) )
				),
				sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( $pm_config_url ),
					esc_html__( 'Configura la conexion', 'patronato-migrator' )
				)
			);
			?>
		</div>
	<?php endif; ?>

	<p class="pm-lead">
		<?php esc_html_e( 'Selecciona un modulo y ejecuta su migracion. Cada modulo procesa sus registros en lotes; puedes detener y reanudar sin perder progreso.', 'patronato-migrator' ); ?>
	</p>

	<div class="pm-modules-list">
		<?php
		foreach ( $pm_modules as $pm_module_data ) {
			$module         = (string) $pm_module_data['module'];
			$label          = (string) $pm_module_data['label'];
			$description    = (string) $pm_module_data['description'];
			$enabled        = (bool) $pm_module_data['enabled'];
			$pending_sprint = isset( $pm_module_data['pending_sprint'] ) ? (string) $pm_module_data['pending_sprint'] : null;

			include PATRONATO_MIGRATOR_INCLUDES_PATH . 'Admin/Views/module-progress.php';
		}
		?>
	</div>

	<footer class="pm-dashboard-footer">
		<a class="pm-footer-link" href="<?php echo esc_url( $pm_config_url ); ?>">
			<?php esc_html_e( 'Configuracion', 'patronato-migrator' ); ?>
		</a>
		<a
			class="pm-footer-link pm-footer-link-disabled"
			href="#"
			aria-disabled="true"
			tabindex="-1"
		>
			<?php esc_html_e( 'Ver log', 'patronato-migrator' ); ?>
		</a>
	</footer>
</div>
