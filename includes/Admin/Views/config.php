<?php
/**
 * Vista: Configuracion de la conexion a Joomla.
 *
 * Renderiza el formulario de credenciales y la prueba de conexion. La logica
 * de guardado vive en el handler de admin-post; esta vista solo presenta.
 *
 * Variables esperadas en el scope:
 *   - $creds (array): host, port, database, username, images_path. Sin password.
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

$creds = isset( $creds ) && is_array( $creds ) ? $creds : array();

$pm_host        = isset( $creds['host'] ) ? (string) $creds['host'] : '';
$pm_port        = isset( $creds['port'] ) && '' !== $creds['port'] ? (string) $creds['port'] : '3306';
$pm_database    = isset( $creds['database'] ) ? (string) $creds['database'] : '';
$pm_username    = isset( $creds['username'] ) ? (string) $creds['username'] : '';
$pm_images_path = isset( $creds['images_path'] ) ? (string) $creds['images_path'] : '';

$pm_msg = isset( $_GET['pm_msg'] ) ? sanitize_key( wp_unslash( (string) $_GET['pm_msg'] ) ) : '';
?>
<div class="wrap pm-wrap">
	<h1><?php esc_html_e( 'Configuracion de Patronato Migrator', 'patronato-migrator' ); ?></h1>

	<?php if ( 'saved' === $pm_msg ) : ?>
		<div class="pm-notice pm-notice-success" role="status">
			<?php esc_html_e( 'Configuracion guardada correctamente.', 'patronato-migrator' ); ?>
		</div>
	<?php elseif ( 'invalid' === $pm_msg ) : ?>
		<div class="pm-notice pm-notice-error" role="alert">
			<?php esc_html_e( 'No se pudo guardar la configuracion. Revisa los campos e intentalo de nuevo.', 'patronato-migrator' ); ?>
		</div>
	<?php endif; ?>

	<div class="pm-card">
		<p class="pm-lead">
			<?php esc_html_e( 'Introduce los datos de conexion a la base de datos de Joomla. Las credenciales se almacenan cifradas.', 'patronato-migrator' ); ?>
		</p>

		<form
			id="pm-config-form"
			class="pm-form"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			autocomplete="off"
		>
			<input type="hidden" name="action" value="pm_save_config">
			<?php wp_nonce_field( 'pm_save_config', '_wpnonce' ); ?>

			<div class="pm-form-grid">
				<div class="pm-field">
					<label for="pm-host"><?php esc_html_e( 'Host', 'patronato-migrator' ); ?> <span class="pm-required" aria-hidden="true">*</span></label>
					<input
						type="text"
						id="pm-host"
						name="host"
						value="<?php echo esc_attr( $pm_host ); ?>"
						required
						autocomplete="off"
						spellcheck="false"
					>
					<p class="pm-help"><?php esc_html_e( 'Direccion del servidor MySQL de Joomla.', 'patronato-migrator' ); ?></p>
				</div>

				<div class="pm-field">
					<label for="pm-port"><?php esc_html_e( 'Puerto', 'patronato-migrator' ); ?> <span class="pm-required" aria-hidden="true">*</span></label>
					<input
						type="number"
						id="pm-port"
						name="port"
						value="<?php echo esc_attr( $pm_port ); ?>"
						min="1"
						max="65535"
						step="1"
						required
					>
					<p class="pm-help"><?php esc_html_e( 'Por defecto 3306.', 'patronato-migrator' ); ?></p>
				</div>

				<div class="pm-field">
					<label for="pm-database"><?php esc_html_e( 'Base de datos', 'patronato-migrator' ); ?> <span class="pm-required" aria-hidden="true">*</span></label>
					<input
						type="text"
						id="pm-database"
						name="database"
						value="<?php echo esc_attr( $pm_database ); ?>"
						required
						autocomplete="off"
						spellcheck="false"
					>
					<p class="pm-help"><?php esc_html_e( 'Nombre de la base de datos Joomla.', 'patronato-migrator' ); ?></p>
				</div>

				<div class="pm-field">
					<label for="pm-username"><?php esc_html_e( 'Usuario', 'patronato-migrator' ); ?> <span class="pm-required" aria-hidden="true">*</span></label>
					<input
						type="text"
						id="pm-username"
						name="username"
						value="<?php echo esc_attr( $pm_username ); ?>"
						required
						autocomplete="off"
						spellcheck="false"
					>
					<p class="pm-help"><?php esc_html_e( 'Usuario MySQL con permisos de lectura.', 'patronato-migrator' ); ?></p>
				</div>

				<div class="pm-field">
					<label for="pm-password"><?php esc_html_e( 'Contrasena', 'patronato-migrator' ); ?></label>
					<input
						type="password"
						id="pm-password"
						name="password"
						value=""
						autocomplete="new-password"
						spellcheck="false"
					>
					<p class="pm-help"><?php esc_html_e( 'Dejar en blanco para conservar la contrasena actual.', 'patronato-migrator' ); ?></p>
				</div>

				<div class="pm-field pm-field-wide">
					<label for="pm-images-path"><?php esc_html_e( 'Ruta de imagenes', 'patronato-migrator' ); ?> <span class="pm-required" aria-hidden="true">*</span></label>
					<input
						type="text"
						id="pm-images-path"
						name="images_path"
						value="<?php echo esc_attr( $pm_images_path ); ?>"
						required
						spellcheck="false"
					>
					<p class="pm-help"><?php esc_html_e( 'Ruta absoluta del servidor a las imagenes de Joomla.', 'patronato-migrator' ); ?></p>
				</div>
			</div>

			<div class="pm-form-actions">
				<button
					type="button"
					id="pm-test-connection-btn"
					class="pm-btn pm-btn-secondary"
				>
					<?php esc_html_e( 'Probar conexion', 'patronato-migrator' ); ?>
				</button>

				<button type="submit" class="pm-btn pm-btn-primary">
					<?php esc_html_e( 'Guardar configuracion', 'patronato-migrator' ); ?>
				</button>
			</div>

			<div
				id="pm-test-connection-result"
				class="pm-test-result"
				role="status"
				aria-live="polite"
			></div>
		</form>
	</div>
</div>
