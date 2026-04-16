<?php
/**
 * Vista: Dashboard del plugin Patronato Migrator.
 *
 * Placeholder del Sprint 1. Se completara en el Sprint 7 con el flujo de
 * migracion completo (modulos, progreso, controles).
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

$pm_config_url = admin_url( 'admin.php?page=patronato-migrator-config' );
?>
<div class="wrap pm-wrap">
	<h1><?php esc_html_e( 'Patronato Migrator', 'patronato-migrator' ); ?></h1>

	<div class="pm-card">
		<p>
			<?php esc_html_e( 'La migracion aun no esta disponible. El panel de control completo se habilitara en una fase posterior.', 'patronato-migrator' ); ?>
		</p>

		<p>
			<?php esc_html_e( 'Por ahora, configura la conexion a la base de datos de Joomla en la pagina de configuracion.', 'patronato-migrator' ); ?>
		</p>

		<p>
			<a class="pm-btn pm-btn-primary" href="<?php echo esc_url( $pm_config_url ); ?>">
				<?php esc_html_e( 'Ir a Configuracion', 'patronato-migrator' ); ?>
			</a>
		</p>
	</div>
</div>
