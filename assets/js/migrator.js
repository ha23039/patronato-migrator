/*
 * Patronato Migrator - Controlador AJAX del panel de administracion.
 *
 * Sprint 1: prueba de conexion a Joomla.
 * Sprint 2: controlador de batches por modulo (loop AJAX, progreso, log).
 */

(function ($) {
	'use strict';

	$(function () {
		// Comprobacion defensiva: la configuracion la inyecta wp_localize_script.
		if (typeof window.PatronatoMigratorConfig !== 'object' || window.PatronatoMigratorConfig === null) {
			return;
		}

		var config = window.PatronatoMigratorConfig;

		initConnectionTest(config);
		initModuleControllers(config);
		initLogViewer(config);
	});

	/* ---------------------------------------------------------------------
	 * Bloque 1 - Prueba de conexion (Sprint 1, intacto)
	 * --------------------------------------------------------------------- */

	function initConnectionTest(config) {
		var $btn    = $('#pm-test-connection-btn');
		var $result = $('#pm-test-connection-result');
		var $form   = $('#pm-config-form');

		if (!$btn.length || !$result.length || !$form.length) {
			return;
		}

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

	}

	/* ---------------------------------------------------------------------
	 * Bloque 2 - Controlador de batches por modulo (Sprint 2)
	 * --------------------------------------------------------------------- */

	/**
	 * Estado por tarjeta. Mapeado por slug de modulo para que cada card mantenga
	 * su propia bandera de ejecucion sin colisiones globales.
	 *
	 * @type {Object.<string, {running: boolean, inFlight: boolean}>}
	 */
	var moduleState = {};

	function initModuleControllers(config) {
		var $cards = $('.pm-module-card[data-pm-module]');

		if (!$cards.length) {
			return;
		}

		$cards.each(function () {
			var $card  = $(this);
			var module = String($card.data('pm-module') || '');

			if (module === '') {
				return;
			}

			var $runBtn  = $card.find('[data-pm-action="run"]');
			var $stopBtn = $card.find('[data-pm-action="stop"]');

			// Cards sin controles (modulos pendientes) no se enganchan.
			if (!$runBtn.length || !$stopBtn.length) {
				return;
			}

			moduleState[module] = { running: false, inFlight: false };

			$runBtn.on('click', function (event) {
				event.preventDefault();
				startModule(module, $card, config);
			});

			$stopBtn.on('click', function (event) {
				event.preventDefault();
				stopModule(module, $card);
			});
		});
	}

	/**
	 * Inicia o reanuda el loop de batches de un modulo.
	 *
	 * @param {string} module Slug del modulo.
	 * @param {jQuery} $card  Tarjeta del modulo.
	 * @param {object} config Configuracion inyectada por wp_localize_script.
	 */
	function startModule(module, $card, config) {
		var state = moduleState[module];
		if (!state || state.running) {
			return;
		}

		state.running = true;
		setRunningUi($card, true);
		clearProgressState($card);
		appendLogEntry($card, getString('starting', 'Iniciando migracion...'), 'info');

		runNextBatch(module, $card, config);
	}

	/**
	 * Marca el modulo como detenido. El batch en vuelo termina y el loop
	 * comprueba la bandera antes de despachar el siguiente.
	 *
	 * @param {string} module Slug del modulo.
	 * @param {jQuery} $card  Tarjeta del modulo.
	 */
	function stopModule(module, $card) {
		var state = moduleState[module];
		if (!state || !state.running) {
			return;
		}

		state.running = false;
		setRunningUi($card, false);
		appendLogEntry($card, getString('stopped', 'Migracion detenida por el usuario.'), 'warning');
	}

	/**
	 * Despacha un unico batch via AJAX. Si la respuesta indica que quedan
	 * lotes pendientes y la bandera running sigue activa, programa el siguiente.
	 *
	 * @param {string} module Slug del modulo.
	 * @param {jQuery} $card  Tarjeta del modulo.
	 * @param {object} config Configuracion inyectada por wp_localize_script.
	 */
	function runNextBatch(module, $card, config) {
		var state = moduleState[module];
		if (!state || !state.running) {
			return;
		}

		state.inFlight = true;

		var payload = {
			action: 'pm_run_batch',
			nonce:  config.nonce,
			module: module
		};

		$.post(config.ajaxUrl, payload)
			.done(function (response) {
				state.inFlight = false;
				handleBatchResponse(module, $card, config, response);
			})
			.fail(function (jqXHR, textStatus) {
				state.inFlight = false;
				handleBatchFailure(module, $card, textStatus);
			});
	}

	/**
	 * Procesa la respuesta JSON de un batch. Actualiza progreso, log y
	 * decide si continuar el loop o cerrarlo.
	 */
	function handleBatchResponse(module, $card, config, response) {
		var state = moduleState[module];

		if (!response || typeof response !== 'object') {
			markFailure($card, getString('bad_response', 'Respuesta invalida del servidor.'));
			finalizeRun(module, $card);
			return;
		}

		if (response.success !== true) {
			var errMessage = (response.data && typeof response.data.message === 'string' && response.data.message.length > 0)
				? response.data.message
				: getString('server_error', 'Error del servidor durante el batch.');
			markFailure($card, errMessage);
			finalizeRun(module, $card);
			return;
		}

		var data = (response.data && typeof response.data === 'object') ? response.data : {};

		var processed  = numberOr(data.processed, 0);
		var total      = numberOr(data.total, 0);
		var cursor     = numberOr(data.cursor, processed);
		var percentage = numberOr(data.percentage, 0);
		var errors     = numberOr(data.errors, 0);
		var skipped    = numberOr(data.skipped, 0);
		var done       = data.done === true;
		var message    = (typeof data.message === 'string' && data.message.length > 0)
			? data.message
			: getString('batch_done', 'Batch completado.');

		updateProgress($card, cursor, total, percentage);

		var entryStatus = 'info';
		if (errors > 0) {
			entryStatus = 'error';
		} else if (skipped > 0) {
			entryStatus = 'warning';
		} else if (done) {
			entryStatus = 'success';
		}
		appendLogEntry($card, message, entryStatus);

		if (done) {
			markSuccess($card);
			appendLogEntry($card, getString('completed', 'Migracion completada.'), 'success');
			finalizeRun(module, $card);
			return;
		}

		// Si el usuario detuvo entre el envio y la respuesta, cerrar limpio.
		if (!state || !state.running) {
			finalizeRun(module, $card);
			return;
		}

		// Continuar con el siguiente batch dejando un margen al navegador.
		window.setTimeout(function () {
			runNextBatch(module, $card, config);
		}, 100);
	}

	/**
	 * Maneja errores de transporte (red, timeout, 5xx sin payload JSON).
	 */
	function handleBatchFailure(module, $card, textStatus) {
		var fallback = getString('network_error', 'Error de red al contactar con el servidor.');
		var detail   = (typeof textStatus === 'string' && textStatus.length > 0) ? ' (' + textStatus + ')' : '';
		markFailure($card, fallback + detail);
		finalizeRun(module, $card);
	}

	/**
	 * Devuelve la card a su estado idle: bandera off, boton run habilitado,
	 * boton stop deshabilitado.
	 */
	function finalizeRun(module, $card) {
		var state = moduleState[module];
		if (state) {
			state.running  = false;
			state.inFlight = false;
		}
		setRunningUi($card, false);
	}

	/* ---------------------------------------------------------------------
	 * Helpers de UI
	 * --------------------------------------------------------------------- */

	function setRunningUi($card, isRunning) {
		$card.find('[data-pm-action="run"]').prop('disabled', isRunning);
		$card.find('[data-pm-action="stop"]').prop('disabled', !isRunning);
	}

	function clearProgressState($card) {
		$card.find('.pm-progress-bar').removeClass('pm-state-success pm-state-error');
	}

	function updateProgress($card, cursor, total, percentage) {
		var pct = Math.max(0, Math.min(100, percentage));

		$card.find('.pm-progress-bar').css('width', pct + '%');
		$card.find('.pm-progress').attr('aria-valuenow', String(Math.round(pct)));

		var totalText = (total > 0) ? String(total) : getString('unknown_total', '—');
		var text      = String(cursor) + ' / ' + totalText + ' (' + formatPercentage(pct) + '%)';
		$card.find('[data-pm-role="progress-text"]').text(text);
	}

	function markSuccess($card) {
		$card.find('.pm-progress-bar')
			.removeClass('pm-state-error')
			.addClass('pm-state-success');
	}

	function markFailure($card, message) {
		$card.find('.pm-progress-bar')
			.removeClass('pm-state-success')
			.addClass('pm-state-error');
		appendLogEntry($card, message, 'error');
	}

	/**
	 * Anade una entrada al log scrollable de la tarjeta. Status admite
	 * 'success', 'error', 'warning', 'info'. La entrada incluye un timestamp
	 * legible y se autoscrollea al final.
	 */
	function appendLogEntry($card, message, status) {
		var $log = $card.find('[data-pm-role="log"]');
		if (!$log.length) {
			return;
		}

		var safeStatus = (status === 'success' || status === 'error' || status === 'warning') ? status : 'info';
		var $entry = $('<div></div>').addClass('pm-log-entry');

		if (safeStatus !== 'info') {
			$entry.addClass('pm-status-' + safeStatus);
		}

		var $time = $('<span></span>').addClass('pm-log-time').text('[' + currentTimestamp() + ']');
		var $msg  = $('<span></span>').addClass('pm-log-message').text(String(message));

		$entry.append($time).append($msg);
		$log.append($entry);

		// Autoscroll al ultimo mensaje sin saltar si el usuario hizo scroll arriba.
		var node = $log.get(0);
		if (node) {
			node.scrollTop = node.scrollHeight;
		}
	}

	/* ---------------------------------------------------------------------
	 * Utilidades puras
	 * --------------------------------------------------------------------- */

	function numberOr(value, fallback) {
		var n = Number(value);
		return isFinite(n) ? n : fallback;
	}

	function formatPercentage(value) {
		// Una decimal cuando aporta informacion, entero cuando es cerrado.
		if (Math.abs(value - Math.round(value)) < 0.05) {
			return String(Math.round(value));
		}
		return value.toFixed(1);
	}

	function currentTimestamp() {
		var d  = new Date();
		var hh = pad2(d.getHours());
		var mm = pad2(d.getMinutes());
		var ss = pad2(d.getSeconds());
		return hh + ':' + mm + ':' + ss;
	}

	function pad2(n) {
		return (n < 10) ? ('0' + n) : String(n);
	}

	/**
	 * Permite que el backend pase strings localizados via PatronatoMigratorConfig.i18n
	 * sin fallar si la clave no esta definida.
	 */
	function getString(key, fallback) {
		var cfg = window.PatronatoMigratorConfig;
		if (cfg && cfg.i18n && typeof cfg.i18n[key] === 'string' && cfg.i18n[key].length > 0) {
			return cfg.i18n[key];
		}
		return fallback;
	}

	/* ---------------------------------------------------------------------
	 * Bloque 3 - Visor de log con filtros, paginacion y exportacion (Sprint 7)
	 * --------------------------------------------------------------------- */

	/**
	 * Inicializa el visor de log si la pagina actual lo contiene. La funcion
	 * es defensiva: si no existe la tabla #pm-log-table, se sale sin tocar
	 * nada para no interferir con el dashboard u otras pantallas.
	 *
	 * @param {object} config Configuracion inyectada por wp_localize_script.
	 */
	function initLogViewer(config) {
		var $table = $('#pm-log-table');
		if (!$table.length) {
			return;
		}

		var $tbody       = $table.find('tbody[data-pm-role="log-rows"]');
		var $moduleSel   = $('#pm-log-module');
		var $statusSel   = $('#pm-log-status');
		var $applyBtn    = $('#pm-log-apply');
		var $loadMoreBtn = $('#pm-log-load-more');
		var $statusText  = $('#pm-log-status-text');
		var $exportLink  = $('[data-pm-export-link]');

		// Estado interno del visor. No se expone al ambito global.
		var state = {
			module:      '',
			status:      '',
			offset:      0,
			limit:       50,
			totalLoaded: 0,
			total:       0,
			loading:     false
		};

		var exportBaseUrl = $exportLink.length ? String($exportLink.data('pm-export-base') || $exportLink.attr('href') || '') : '';

		// Carga inicial sin filtros.
		loadLog(true);

		$applyBtn.on('click', function (event) {
			event.preventDefault();
			state.module = String($moduleSel.val() || '');
			state.status = String($statusSel.val() || '');
			loadLog(true);
		});

		$loadMoreBtn.on('click', function (event) {
			event.preventDefault();
			loadLog(false);
		});

		$moduleSel.on('change', function () {
			updateExportLink(String($moduleSel.val() || ''));
		});

		// Sincroniza el href del enlace de exportacion al cambiar el modulo.
		// Si el valor esta vacio se elimina el parametro module.
		function updateExportLink(moduleSlug) {
			if (!$exportLink.length || exportBaseUrl === '') {
				return;
			}

			var nextHref = exportBaseUrl;
			if (moduleSlug !== '') {
				var separator = (nextHref.indexOf('?') === -1) ? '?' : '&';
				nextHref = nextHref + separator + 'module=' + encodeURIComponent(moduleSlug);
			}

			$exportLink.attr('href', nextHref);
		}

		/**
		 * Despacha la peticion AJAX al endpoint pm_log_fetch.
		 *
		 * @param {boolean} reset Si true, vacia la tabla y reinicia offset.
		 */
		function loadLog(reset) {
			if (state.loading) {
				return;
			}

			if (reset) {
				state.offset      = 0;
				state.totalLoaded = 0;
				$tbody.empty();
				$loadMoreBtn.prop('hidden', true).prop('disabled', true);
				setStatusText(getString('log_loading', 'Cargando...'), false);
			} else {
				$loadMoreBtn.prop('disabled', true);
			}

			state.loading = true;

			var payload = {
				action: 'pm_log_fetch',
				nonce:  config.nonce,
				module: state.module,
				status: state.status,
				limit:  state.limit,
				offset: state.offset
			};

			$.post(config.ajaxUrl, payload)
				.done(function (response) {
					handleLogResponse(response);
				})
				.fail(function (jqXHR, textStatus) {
					var detail = (typeof textStatus === 'string' && textStatus.length > 0) ? ' (' + textStatus + ')' : '';
					setStatusText(getString('log_network_error', 'Error de red al cargar el log.') + detail, true);
				})
				.always(function () {
					state.loading = false;
					$loadMoreBtn.prop('disabled', false);
				});
		}

		function handleLogResponse(response) {
			if (!response || typeof response !== 'object' || response.success !== true) {
				var errMsg = getString('log_server_error', 'Error del servidor al cargar el log.');
				if (response && response.data && typeof response.data.message === 'string' && response.data.message.length > 0) {
					errMsg = response.data.message;
				}
				setStatusText(errMsg, true);
				return;
			}

			var data  = (response.data && typeof response.data === 'object') ? response.data : {};
			var rows  = Array.isArray(data.rows) ? data.rows : [];
			var total = numberOr(data.total, 0);
			var limit = numberOr(data.limit, state.limit);

			renderRows(rows);

			state.total        = total;
			state.totalLoaded += rows.length;
			state.offset      += rows.length;
			state.limit        = limit > 0 ? limit : state.limit;

			updateFooter();
		}

		function renderRows(rows) {
			if (rows.length === 0 && state.totalLoaded === 0) {
				var $emptyRow = $('<tr></tr>').addClass('pm-row-empty');
				$('<td></td>')
					.attr('colspan', 7)
					.text(getString('log_empty', 'No hay entradas que coincidan con los filtros.'))
					.appendTo($emptyRow);
				$tbody.append($emptyRow);
				return;
			}

			for (var i = 0; i < rows.length; i++) {
				$tbody.append(buildRow(rows[i]));
			}
		}

		function buildRow(row) {
			var statusKey = (row && typeof row.status === 'string') ? row.status : '';
			var safeStatus = (statusKey === 'success' || statusKey === 'error' || statusKey === 'warning' || statusKey === 'skipped')
				? statusKey
				: 'info';

			// Construccion via DOM API y .text() para evitar inyeccion XSS;
			// jQuery escapa por nosotros al usar .text() en cada celda.
			var $tr = $('<tr></tr>').addClass('pm-row-status-' + safeStatus);

			$tr.append($('<td></td>').addClass('pm-cell-id').text(safeString(row && row.id)));
			$tr.append($('<td></td>').addClass('pm-cell-date').text(safeString(row && row.created_at)));
			$tr.append($('<td></td>').addClass('pm-cell-module').text(safeString(row && row.module)));
			$tr.append($('<td></td>').addClass('pm-cell-status').text(safeString(statusKey)));
			$tr.append($('<td></td>').addClass('pm-cell-joomla').text(safeString(row && row.joomla_id)));
			$tr.append($('<td></td>').addClass('pm-cell-wp').text(safeString(row && row.wp_id)));

			var message = (row && typeof row.message === 'string') ? row.message : '';
			$tr.append(
				$('<td></td>')
					.addClass('pm-cell-message')
					.attr('title', message)
					.text(message)
			);

			return $tr;
		}

		function updateFooter() {
			var template = getString('log_count', '%1$s de %2$s entradas');
			var text = template
				.replace('%1$s', String(state.totalLoaded))
				.replace('%2$s', String(state.total));
			setStatusText(text, false);

			if (state.totalLoaded < state.total) {
				$loadMoreBtn.prop('hidden', false);
			} else {
				$loadMoreBtn.prop('hidden', true);
			}
		}

		function setStatusText(text, isError) {
			$statusText.text(String(text));
			if (isError) {
				$statusText.addClass('pm-status-error');
			} else {
				$statusText.removeClass('pm-status-error');
			}
		}

		function safeString(value) {
			if (value === null || typeof value === 'undefined') {
				return '';
			}
			return String(value);
		}
	}

	/**
	 * Escapa los caracteres reservados de HTML. Se mantiene disponible aunque
	 * el visor de log construye el DOM con jQuery.text() para una segunda capa
	 * de defensa cuando se necesite inyectar markup.
	 *
	 * @param {string} str Texto a escapar.
	 * @return {string}    Texto seguro para insertar como innerHTML.
	 */
	function escapeHtml(str) {
		if (str === null || typeof str === 'undefined') {
			return '';
		}
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}
})(jQuery);
