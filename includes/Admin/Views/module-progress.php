<?php
/**
 * Vista parcial reusable: tarjeta de estado de un modulo de migracion.
 *
 * Renderiza una card por modulo con su barra de progreso, controles
 * Iniciar/Detener y un area de log. Para los modulos aun no habilitados
 * muestra solo un badge "Pendiente — Sprint X".
 *
 * Variables esperadas en el scope:
 *   - $module          (string)      Slug del modulo: categories|products|images|customers|orders|redirects.
 *   - $label           (string)      Nombre visible del modulo. Ej: "Categorias".
 *   - $description     (string)      Texto breve descriptivo. Ej: "316 categorias de JoomShopping".
 *   - $enabled         (bool)        Si false, no se renderiza la barra ni los controles.
 *   - $pending_sprint  (string|null) Texto del sprint pendiente. Solo aplica cuando $enabled === false.
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

$pm_module         = isset( $module ) ? (string) $module : '';
$pm_label          = isset( $label ) ? (string) $label : '';
$pm_description    = isset( $description ) ? (string) $description : '';
$pm_enabled        = isset( $enabled ) ? (bool) $enabled : false;
$pm_pending_sprint = isset( $pending_sprint ) ? (string) $pending_sprint : '';

if ( '' === $pm_module || '' === $pm_label ) {
	return;
}

$pm_card_id     = 'pm-module-' . $pm_module;
$pm_progress_id = $pm_card_id . '-progress';
$pm_log_id      = $pm_card_id . '-log';
?>
<section
	class="pm-module-card"
	id="<?php echo esc_attr( $pm_card_id ); ?>"
	data-pm-module="<?php echo esc_attr( $pm_module ); ?>"
	aria-labelledby="<?php echo esc_attr( $pm_card_id . '-title' ); ?>"
>
	<header class="pm-module-card-header">
		<h2 id="<?php echo esc_attr( $pm_card_id . '-title' ); ?>">
			<?php echo esc_html( $pm_label ); ?>
		</h2>
		<p class="pm-module-card-desc">
			<?php echo esc_html( $pm_description ); ?>
		</p>
	</header>

	<?php if ( $pm_enabled ) : ?>
		<div
			class="pm-progress"
			role="progressbar"
			aria-valuemin="0"
			aria-valuemax="100"
			aria-valuenow="0"
			aria-labelledby="<?php echo esc_attr( $pm_card_id . '-title' ); ?>"
			id="<?php echo esc_attr( $pm_progress_id ); ?>"
		>
			<div class="pm-progress-bar" style="width: 0%;"></div>
		</div>

		<p class="pm-progress-text" data-pm-role="progress-text">
			<?php
			printf(
				/* translators: 1: procesados, 2: total, 3: porcentaje. */
				esc_html__( '%1$s / %2$s (%3$s%%)', 'patronato-migrator' ),
				'0',
				esc_html__( '—', 'patronato-migrator' ),
				'0'
			);
			?>
		</p>

		<div class="pm-actions">
			<button
				type="button"
				class="pm-btn pm-btn-primary"
				data-pm-action="run"
			>
				<?php esc_html_e( 'Iniciar / Reanudar', 'patronato-migrator' ); ?>
			</button>
			<button
				type="button"
				class="pm-btn pm-btn-secondary"
				data-pm-action="stop"
				disabled
			>
				<?php esc_html_e( 'Detener', 'patronato-migrator' ); ?>
			</button>
		</div>

		<div
			class="pm-module-log"
			id="<?php echo esc_attr( $pm_log_id ); ?>"
			data-pm-role="log"
			role="log"
			aria-live="polite"
			aria-atomic="false"
		></div>
	<?php else : ?>
		<p class="pm-module-pending">
			<span class="pm-badge pm-badge-pending">
				<?php
				if ( '' !== $pm_pending_sprint ) {
					printf(
						/* translators: %s: sprint pendiente, ej. "Sprint 3". */
						esc_html__( 'Pendiente — %s', 'patronato-migrator' ),
						esc_html( $pm_pending_sprint )
					);
				} else {
					esc_html_e( 'Pendiente', 'patronato-migrator' );
				}
				?>
			</span>
		</p>
	<?php endif; ?>
</section>
