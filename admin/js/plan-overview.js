(function () {
	'use strict';

	// Animate progress bars after render.
	document.addEventListener('DOMContentLoaded', function () {
		var bars = document.querySelectorAll('#co-admin-wrap .co-bar-fill[data-pct]');

		bars.forEach(function (bar) {
			var pct = parseInt(bar.getAttribute('data-pct'), 10) || 0;
			// Small delay so the animation is visible.
			setTimeout(function () {
				bar.style.width = pct + '%';
			}, 120);
		});
	});
}());
