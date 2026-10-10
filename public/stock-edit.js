/**
 * Set-stock page: saves one product's figure, then returns to the stock list.
 */
(function () {
    'use strict';

    const config = window.StockConfig || {};
    const form = document.getElementById('stockForm');
    if (!form) return;

    const result = document.getElementById('stockResult');
    const saveButton = document.getElementById('stockSaveButton');

    function showResult(message, type) {
        result.className = 'alert alert-' + type;
        result.textContent = message;
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        saveButton.disabled = true;

        try {
            const response = await fetch(config.actionUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: window.csrfToken,
                    action: 'save',
                    id: Number(document.getElementById('stockProductId').value) || 0,
                    quantity: document.getElementById('stockQuantity').value,
                    reorder_level: document.getElementById('stockReorder').value,
                    on_order: document.getElementById('stockOnOrder').value,
                    expected_at: document.getElementById('stockExpected').value,
                    note: document.getElementById('stockNote').value
                })
            });
            const data = await response.json();

            if (!data.success) {
                showResult(data.error || data.message || 'Could not save the stock figure.', 'danger');
                saveButton.disabled = false;
                return;
            }

            window.location.href = config.listUrl;
        } catch (error) {
            console.error('Save error:', error);
            showResult('Could not save the stock figure.', 'danger');
            saveButton.disabled = false;
        }
    });
})();
