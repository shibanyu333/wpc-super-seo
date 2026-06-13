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

	function runAdminAction($button) {
		var $box = $button.closest('[data-super-seo-box]');

		if (!$box.length) {
			$box = $('.super-seo-wrap');
		}

		$button.prop('disabled', true);
		setStatus($box, SuperSEO.working, false);

		$.post(SuperSEO.ajaxUrl, {
			action: $button.data('super-seo-action'),
			nonce: SuperSEO.nonce
		}).done(function (response) {
			if (!response || !response.success) {
				setStatus($box, SuperSEO.errorPrefix + (response && response.data ? response.data : '未知错误'), true);
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
				setStatus($box, SuperSEO.errorPrefix + (response && response.data ? response.data : '未知错误'), true);
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
				setStatus($box, SuperSEO.errorPrefix + (response && response.data ? response.data : '未知错误'), true);
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
})(jQuery);
