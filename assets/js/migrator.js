/*
 * Patronato Migrator - Controlador AJAX del panel de administracion.
 *
 * Sprint 1: solo prueba de conexion a Joomla.
 */

(function ($) {
	'use strict';

	$(function () {
		var $btn    = $('#pm-test-connection-btn');
		var $result = $('#pm-test-connection-result');
		var $form   = $('#pm-config-form');

		if (!$btn.length || !$result.length || !$form.length) {
			return;
		}

		// Comprobacion defensiva: la configuracion la inyecta wp_localize_script.
		if (typeof window.PatronatoMigratorConfig !== 'object' || window.PatronatoMigratorConfig === null) {
			return;
		}

		var config = window.PatronatoMigratorConfig;

		/**
		 * Renderiza un mensaje en el contenedor de resultado.
		 *
		 * @param {string} message Texto a mostrar.
		 * @param {string} state   'loading' | 'success' | 'error'.
		 */
		function renderResult(message, state) {
			var className = 'pm-result-' + state;
			$result
				.removeClass('pm-result-loading pm-result-success pm-result-error')
				.addClass(className)
				.text(message);
		}

		/**
		 * Lee los valores del formulario de configuracion por nombre de campo.
		 *
		 * @return {object} payload listo para enviar.
		 */
		function readFormValues() {
			return {
				host:        $form.find('input[name="host"]').val() || '',
				port:        $form.find('input[name="port"]').val() || '',
				database:    $form.find('input[name="database"]').val() || '',
				username:    $form.find('input[name="username"]').val() || '',
				password:    $form.find('input[name="password"]').val() || '',
				images_path: $form.find('input[name="images_path"]').val() || ''
			};
		}

		$btn.on('click', function (event) {
			event.preventDefault();

			if ($btn.prop('disabled')) {
				return;
			}

			var values = readFormValues();

			renderResult('Probando conexion...', 'loading');
			$btn.prop('disabled', true);

			var payload = $.extend(
				{
					action: 'pm_test_connection',
					nonce:  config.nonce
				},
				values
			);

			$.post(config.ajaxUrl, payload)
				.done(function (response) {
					var message = 'Respuesta inesperada del servidor.';
					var state   = 'error';

					if (response && typeof response === 'object') {
						if (response.data && typeof response.data.message === 'string' && response.data.message.length > 0) {
							message = response.data.message;
						} else if (response.success) {
							message = 'Conexion exitosa.';
						}

						if (response.success === true) {
							state = 'success';
						}
					}

					renderResult(message, state);
				})
				.fail(function () {
					renderResult(
						'Error de red al contactar con el servidor. Intentalo de nuevo.',
						'error'
					);
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		});

		// Controlador de batches: pendiente Sprint 2
	});
})(jQuery);
