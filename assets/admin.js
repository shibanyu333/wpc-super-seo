(function ($) {
	'use strict';

	function setStatus($box, message, isError) {
		var $status = $box.find('[data-super-seo-status]').first();

		if (!$status.length) {
			$status = $('[data-super-seo-test-status]').first();
		}

		$status
			.text(message || '')
			.toggleClass('is-error', !!isError)
			.toggleClass('is-ok', !isError && !!message);
	}

	function fillFields($box, data) {
		$box.find('[data-super-seo-field="title"]').val(data.title || '');
		$box.find('[data-super-seo-field="description"]').val(data.description || '');
		$box.find('[data-super-seo-field="keywords"]').val(data.keywords || '');
	}

	function errorText(response) {
		if (response && response.data) {
			return typeof response.data === 'string' ? response.data : '未知错误';
		}

		return '未知错误';
	}

	function runAdminAction($button) {
		var confirmText = $button.data('super-seo-confirm');

		if (confirmText && !window.confirm(confirmText)) {
			return;
		}

		var $box = $button.closest('[data-super-seo-box]');

		if (!$box.length) {
			$box = $('.super-seo-wrap');
		}

		$button.prop('disabled', true);
		setStatus($box, SuperSEO.working, false);

		var payload = $.extend({
			action: $button.data('super-seo-action'),
			nonce: SuperSEO.nonce
		}, $button.data('super-seo-payload') || {});

		$.post(SuperSEO.ajaxUrl, payload).done(function (response) {
			if (!response || !response.success) {
				setStatus($box, SuperSEO.errorPrefix + errorText(response), true);
				return;
			}

			setStatus($box, SuperSEO.actionDone, false);
			window.setTimeout(function () {
				window.location.reload();
			}, 700);
		}).fail(function () {
			setStatus($box, SuperSEO.errorPrefix + '请求失败', true);
		}).always(function () {
			$button.prop('disabled', false);
		});
	}

	// --- SEO meta generation -------------------------------------------------

	$(document).on('click', '.super-seo-ai-button', function () {
		var $button = $(this);
		var $box = $button.closest('[data-super-seo-box]');

		if (!$box.length) {
			$box = $button.closest('table, .form-table');
		}

		if (!$box.length) {
			$box = $button.closest('td, .super-seo-metabox');
		}

		$button.prop('disabled', true);
		setStatus($box, SuperSEO.generating, false);

		$.post(SuperSEO.ajaxUrl, {
			action: 'super_seo_generate_meta',
			nonce: SuperSEO.nonce,
			object_type: $button.data('object-type'),
			object_id: $button.data('object-id')
		}).done(function (response) {
			if (!response || !response.success) {
				setStatus($box, SuperSEO.errorPrefix + errorText(response), true);
				return;
			}

			fillFields($box, response.data);
			setStatus($box, SuperSEO.generated, false);
		}).fail(function () {
			setStatus($box, SuperSEO.errorPrefix + '请求失败', true);
		}).always(function () {
			$button.prop('disabled', false);
		});
	});

	$(document).on('click', '.super-seo-test-ai', function () {
		var $button = $(this);
		var $box = $('.super-seo-wrap');

		$button.prop('disabled', true);
		setStatus($box, SuperSEO.testing, false);

		$.post(SuperSEO.ajaxUrl, {
			action: 'super_seo_test_ai',
			nonce: SuperSEO.nonce
		}).done(function (response) {
			if (!response || !response.success) {
				setStatus($box, SuperSEO.errorPrefix + errorText(response), true);
				return;
			}

			setStatus($box, SuperSEO.testSuccess + ' 示例标题：' + response.data.title, false);
		}).fail(function () {
			setStatus($box, SuperSEO.errorPrefix + '请求失败', true);
		}).always(function () {
			$button.prop('disabled', false);
		});
	});

	$(document).on('click', '.super-seo-action-button', function () {
		runAdminAction($(this));
	});

	// --- Provider defaults ---------------------------------------------------

	function providerDefaults() {
		return (SuperSEO && SuperSEO.providers) || {};
	}

	function isKnownDefault(value, field) {
		var defaults = providerDefaults();
		var key;

		for (key in defaults) {
			if (Object.prototype.hasOwnProperty.call(defaults, key) && defaults[key][field] === value) {
				return true;
			}
		}

		return false;
	}

	function applyProvider($select, force) {
		var role = $select.data('super-seo-provider');
		var config = providerDefaults()[$select.val()];

		if (!config) {
			return;
		}

		var endpointRole = 'vision' === role ? 'vision-endpoint' : 'endpoint';
		var modelRole = 'vision' === role ? 'vision-model' : 'model';
		var $endpoint = $('[data-super-seo-field-role="' + endpointRole + '"]');
		var $model = $('[data-super-seo-field-role="' + modelRole + '"]');
		var modelField = 'vision' === role ? 'visionModel' : 'model';

		// Only overwrite fields the user has not customised.
		if ($endpoint.length && (force || '' === $endpoint.val() || isKnownDefault($endpoint.val(), 'endpoint'))) {
			$endpoint.val(config.endpoint);
		}

		if ($model.length && (force || '' === $model.val() || isKnownDefault($model.val(), 'model') || isKnownDefault($model.val(), 'visionModel'))) {
			$model.val(config[modelField]);
		}

		$('[data-super-seo-provider-note="' + role + '"]').text(config.note || '');
	}

	$(document).on('change', '[data-super-seo-provider]', function () {
		applyProvider($(this), true);
	});

	$(function () {
		$('[data-super-seo-provider]').each(function () {
			applyProvider($(this), false);
		});
	});

	// --- Image alt text ------------------------------------------------------

	$(document).on('click', '.super-seo-vision-button', function () {
		var $button = $(this);
		var $box = $button.closest('[data-super-seo-box]');

		if (!$box.length) {
			$box = $button.parent();
		}

		$button.prop('disabled', true);
		setStatus($box, SuperSEO.visionDoing, false);

		$.post(SuperSEO.ajaxUrl, {
			action: 'super_seo_vision_describe',
			nonce: SuperSEO.nonce,
			attachment_id: $button.data('attachment-id'),
			overwrite: $button.data('overwrite') ? 1 : 0
		}).done(function (response) {
			if (!response || !response.success) {
				setStatus($box, SuperSEO.errorPrefix + errorText(response), true);
				return;
			}

			var alt = response.data.alt || '（判定为装饰性图片，alt 留空）';

			// Update the alt field in place when the media modal is open.
			var $altField = $button.closest('.media-sidebar, .compat-attachment-fields, tr, .media-modal')
				.find('[data-setting="alt"] input, [data-setting="alt"] textarea, textarea[name*="_wp_attachment_image_alt"], input[name*="_wp_attachment_image_alt"]')
				.first();

			if ($altField.length && response.data.alt) {
				$altField.val(response.data.alt).trigger('change');
			}

			setStatus($box, SuperSEO.visionDone + ' ' + alt, false);
		}).fail(function () {
			setStatus($box, SuperSEO.errorPrefix + '请求失败', true);
		}).always(function () {
			$button.prop('disabled', false);
		});
	});

	var batchRunning = false;

	$(document).on('click', '[data-super-seo-vision-batch]', function () {
		var $button = $(this);
		var $box = $button.closest('[data-super-seo-box]');
		var $log = $box.find('[data-super-seo-vision-log]');
		var totals = { ok: 0, fail: 0, remaining: undefined };

		if (batchRunning) {
			batchRunning = false;
			$button.text(SuperSEO.batchIdle);
			return;
		}

		batchRunning = true;
		$button.text(SuperSEO.batchStop);
		$log.prop('hidden', false).empty();

		function appendLog(items) {
			$.each(items || [], function (index, item) {
				var line = $('<p/>');
				var ms = item.elapsed ? ' (' + (item.elapsed / 1000).toFixed(1) + 's)' : '';

				if (item.error) {
					line.addClass('is-error').text((item.retry ? '⏸ ' : '✗ ') + (item.title || item.id) + '：' + item.error);
				} else {
					line.text('✓ ' + (item.title || item.id) + '：' + (item.alt || '（装饰性图片）') + ms);
				}

				$log.prepend(line);
			});

			// Keep the log from growing without bound during long runs.
			$log.find('p').slice(60).remove();
		}

		function stop(message, isError) {
			batchRunning = false;
			$button.text(SuperSEO.batchIdle);
			setStatus($box, message, !!isError);
		}

		function summary(data) {
			var s = '成功 ' + totals.ok + ' 张，失败 ' + totals.fail + ' 张';

			if (data && data.usage && data.usage.images) {
				s += '，累计消耗 输入 ' + data.usage.input.toLocaleString() +
					' / 输出 ' + data.usage.output.toLocaleString() + ' token';
			}

			return s;
		}

		function step() {
			if (!batchRunning) {
				stop('已停止。' + summary());
				return;
			}

			setStatus($box, '正在识别... 已完成 ' + totals.ok + ' 张，剩余 ' + (totals.remaining === undefined ? '?' : totals.remaining) + ' 张', false);

			$.post(SuperSEO.ajaxUrl, {
				action: 'super_seo_vision_batch',
				nonce: SuperSEO.nonce,
				overwrite: $('input[name="vision_overwrite_existing"]').is(':checked') ? 1 : 0
			}).done(function (response) {
				if (!response || !response.success) {
					stop(SuperSEO.errorPrefix + errorText(response), true);
					return;
				}

				var data = response.data;
				totals.ok += data.succeeded || 0;
				totals.fail += data.failed || 0;
				totals.remaining = data.remaining;
				appendLog(data.items);

				// Rate limit / outage: progress is kept, nothing was marked failed.
				if (data.paused) {
					stop('已暂停：' + data.paused + ' 进度已保留（' + summary(data) + '），恢复后再点一次继续。', true);
					return;
				}

				if (!data.processed || !data.remaining) {
					stop('全部完成。' + summary(data));
					return;
				}

				window.setTimeout(step, 400);
			}).fail(function (xhr) {
				var hint = (xhr && xhr.status === 504) ? '服务器超时，请把「每批处理数量」调小后继续。' : '请求失败，进度已保留，重新点击即可继续。';
				stop(SuperSEO.errorPrefix + hint, true);
			});
		}

		step();
	});

	$(window).on('beforeunload', function () {
		if (batchRunning) {
			return '批量识别还在进行中，离开会中断任务。';
		}
	});
})(jQuery);
