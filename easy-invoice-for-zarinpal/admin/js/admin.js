(function () {
	'use strict';

	if (typeof window.ezinvAdmin === 'undefined') {
		return;
	}

	var config = window.ezinvAdmin;
	var searchInput = document.getElementById('ezinv-product-search');
	var resultsBox = document.getElementById('ezinv-product-results');
	var itemsBody = document.getElementById('ezinv-items-body');
	var manualAmount = document.getElementById('ezinv-amount');
	var shippingCheckbox = document.getElementById('ezinv-include-shipping');
	var selected = new Map();
	var timer = null;

	function number(value) {
		return new Intl.NumberFormat().format(Math.max(0, Number(value) || 0));
	}

	function money(value) {
		return number(value) + ' ' + config.i18n.toman;
	}

	function createCell(text) {
		var td = document.createElement('td');
		td.textContent = text;
		return td;
	}

	function renderItems() {
		if (!itemsBody) {
			return;
		}
		itemsBody.replaceChildren();

		if (selected.size === 0) {
			var emptyRow = document.createElement('tr');
			var emptyCell = document.createElement('td');
			emptyCell.colSpan = 5;
			emptyCell.className = 'ezinv-empty-products';
			emptyCell.textContent = config.i18n.emptyProducts;
			emptyRow.appendChild(emptyCell);
			itemsBody.appendChild(emptyRow);
			updateSummary();
			return;
		}

		selected.forEach(function (item) {
			var row = document.createElement('tr');
			var nameCell = document.createElement('td');
			var nameStrong = document.createElement('strong');
			nameStrong.textContent = item.name;
			nameCell.appendChild(nameStrong);
			if (item.sku) {
				var sku = document.createElement('small');
				sku.textContent = 'SKU: ' + item.sku;
				nameCell.appendChild(document.createElement('br'));
				nameCell.appendChild(sku);
			}

			var hiddenId = document.createElement('input');
			hiddenId.type = 'hidden';
			hiddenId.name = 'product_ids[]';
			hiddenId.value = String(item.id);
			nameCell.appendChild(hiddenId);
			row.appendChild(nameCell);
			row.appendChild(createCell(money(item.price_toman)));

			var qtyCell = document.createElement('td');
			var qty = document.createElement('input');
			qty.type = 'number';
			qty.name = 'quantities[]';
			qty.min = '1';
			qty.max = '999';
			qty.step = '1';
			qty.className = 'ezinv-item-qty';
			qty.value = String(item.quantity);
			qty.addEventListener('input', function () {
				item.quantity = Math.min(999, Math.max(1, parseInt(qty.value, 10) || 1));
				qty.value = String(item.quantity);
				lineCell.textContent = money(item.price_toman * item.quantity);
				updateSummary();
			});
			qtyCell.appendChild(qty);
			row.appendChild(qtyCell);

			var lineCell = createCell(money(item.price_toman * item.quantity));
			row.appendChild(lineCell);

			var actionCell = document.createElement('td');
			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'ezinv-remove-item';
			remove.textContent = config.i18n.remove;
			remove.addEventListener('click', function () {
				selected.delete(item.id);
				renderItems();
			});
			actionCell.appendChild(remove);
			row.appendChild(actionCell);
			itemsBody.appendChild(row);
		});

		updateSummary();
	}

	function updateSummary() {
		var subtotal = 0;
		if (selected.size > 0) {
			selected.forEach(function (item) {
				subtotal += item.price_toman * item.quantity;
			});
		} else if (manualAmount) {
			subtotal = Math.max(0, parseInt(manualAmount.value, 10) || 0);
		}
		var shipping = shippingCheckbox && shippingCheckbox.checked ? Math.max(0, Number(config.shippingToman) || 0) : 0;
		var subtotalNode = document.getElementById('ezinv-summary-subtotal');
		var shippingNode = document.getElementById('ezinv-summary-shipping');
		var totalNode = document.getElementById('ezinv-summary-total');
		if (subtotalNode) subtotalNode.textContent = money(subtotal);
		if (shippingNode) shippingNode.textContent = money(shipping);
		if (totalNode) totalNode.textContent = money(subtotal + shipping);
	}

	function hideResults() {
		if (resultsBox) {
			resultsBox.style.display = 'none';
			resultsBox.replaceChildren();
		}
	}

	function renderResults(items) {
		if (!resultsBox) return;
		resultsBox.replaceChildren();
		if (!items.length) {
			var empty = document.createElement('div');
			empty.className = 'ezinv-product-result';
			empty.textContent = config.i18n.noResults;
			resultsBox.appendChild(empty);
			resultsBox.style.display = 'block';
			return;
		}

		items.forEach(function (item) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'ezinv-product-result';
			button.setAttribute('role', 'option');
			var info = document.createElement('span');
			var title = document.createElement('span');
			title.className = 'ezinv-result-name';
			title.textContent = item.name;
			info.appendChild(title);
			if (item.sku) {
				var meta = document.createElement('div');
				meta.className = 'ezinv-result-meta';
				meta.textContent = 'SKU: ' + item.sku;
				info.appendChild(meta);
			}
			var price = document.createElement('span');
			price.className = 'ezinv-result-price';
			price.textContent = money(item.price_toman);
			button.appendChild(info);
			button.appendChild(price);
			button.addEventListener('click', function () {
				if (!selected.has(item.id)) {
					selected.set(item.id, {
						id: Number(item.id),
						name: String(item.name || ''),
						sku: String(item.sku || ''),
						price_toman: Math.max(0, Number(item.price_toman) || 0),
						quantity: 1
					});
				}
				renderItems();
				hideResults();
				searchInput.value = '';
			});
			resultsBox.appendChild(button);
		});
		resultsBox.style.display = 'block';
	}

	function searchProducts(term) {
		var body = new URLSearchParams();
		body.set('action', 'ezinv_search_products');
		body.set('nonce', config.productNonce);
		body.set('term', term);
		fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !Array.isArray(payload.data)) {
				throw new Error('search_failed');
			}
			renderResults(payload.data);
		}).catch(function () {
			if (resultsBox) {
				resultsBox.replaceChildren();
				var error = document.createElement('div');
				error.className = 'ezinv-product-result';
				error.textContent = config.i18n.searchError;
				resultsBox.appendChild(error);
				resultsBox.style.display = 'block';
			}
		});
	}

	if (searchInput) {
		searchInput.addEventListener('input', function () {
			window.clearTimeout(timer);
			var term = searchInput.value.trim();
			if (term.length < 2) {
				hideResults();
				return;
			}
			timer = window.setTimeout(function () { searchProducts(term); }, 250);
		});
	}

	if (manualAmount) manualAmount.addEventListener('input', updateSummary);
	if (shippingCheckbox) shippingCheckbox.addEventListener('change', updateSummary);

	document.addEventListener('click', function (event) {
		if (resultsBox && searchInput && !resultsBox.contains(event.target) && event.target !== searchInput) {
			hideResults();
		}
	});

	document.querySelectorAll('.ezinv-copy').forEach(function (button) {
		button.addEventListener('click', function () {
			var url = button.getAttribute('data-url') || '';
			if (!url || !navigator.clipboard) return;
			navigator.clipboard.writeText(url).then(function () {
				button.textContent = config.i18n.copied;
				window.setTimeout(function () { button.textContent = config.i18n.copyLabel; }, 1400);
			}).catch(function () {
				button.textContent = config.i18n.copyFailed;
			});
		});
	});

	document.querySelectorAll('.ezinv-delete-form').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (!window.confirm(config.i18n.deleteConfirm)) {
				event.preventDefault();
			}
		});
	});

	updateSummary();
}());
