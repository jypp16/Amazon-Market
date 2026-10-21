document.addEventListener('DOMContentLoaded', function() {
    var tabs = document.querySelectorAll('.inventory-adjustment-tab');
    if (!tabs.length) return;

    var description = document.querySelector('.inventory-adjustment-description');
    var input = document.getElementById('writeoff-product');
    var results = document.getElementById('writeoff-product-results');
    var summary = {
        name: document.getElementById('writeoff-summary-name'),
        category: document.getElementById('writeoff-summary-category'),
        subcategory: document.getElementById('writeoff-summary-subcategory'),
        stock: document.getElementById('writeoff-summary-stock')
    };
    var quantityInput = document.getElementById('writeoff-quantity');
    var justificationInput = document.getElementById('writeoff-justification');
    var productError = document.getElementById('writeoff-product-error');
    var quantityError = document.getElementById('writeoff-quantity-error');
    var justificationError = document.getElementById('writeoff-justification-error');
    var reviewButton = document.getElementById('writeoff-review-button');
    var cancelButton = document.getElementById('writeoff-cancel-button');
    var reasonSelect = document.getElementById('writeoff-reason');
    var lotInput = document.getElementById('writeoff-lot');
    var confirmation = document.getElementById('writeoff-confirmation');
    var confirmationClose = confirmation.querySelector('.inventory-confirmation-close');
    var confirmationBack = confirmation.querySelector('.inventory-confirmation-back');
    var confirmationTitle = document.getElementById('writeoff-confirmation-title');
    var confirmationIntro = confirmation.querySelector('.inventory-confirmation-intro');
    var confirmationMovementLabel = document.getElementById('writeoff-confirm-movement-label');
    var confirmationLotRow = document.getElementById('writeoff-confirm-lot-row');
    var confirmationJustificationLabel = document.getElementById('writeoff-confirm-justification-label');
    var confirmationNote = document.getElementById('writeoff-confirm-note');
    var confirmationSubmit = confirmation.querySelector('.inventory-confirmation-submit');
    var activeReviewButton = document.getElementById('writeoff-review-button');
    var confirmationFields = {
        product: document.getElementById('writeoff-confirm-product'),
        barcode: document.getElementById('writeoff-confirm-barcode'),
        quantityReason: document.getElementById('writeoff-confirm-quantity-reason'),
        lot: document.getElementById('writeoff-confirm-lot'),
        equation: document.getElementById('writeoff-confirm-equation'),
        balance: document.getElementById('writeoff-confirm-summary'),
        justification: document.getElementById('writeoff-confirm-justification')
    };
    var balance = {
        before: document.getElementById('writeoff-balance-before'),
        deduction: document.getElementById('writeoff-balance-deduction'),
        after: document.getElementById('writeoff-balance-after')
    };
    var selectedStock = null;
    var selectedProduct = null;
    var validationAttempted = false;
    var searchSequence = 0;
    var searchTimer;

    tabs.forEach(function(button) {
        button.addEventListener('click', function() {
            tabs.forEach(function(tab) {
                var isSelected = tab === button;
                tab.classList.toggle('is-active', isSelected);
                tab.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            });
            description.textContent = button.dataset.description;
            document.querySelectorAll('[data-inventory-panel]').forEach(function(panel) {
                panel.hidden = panel.dataset.inventoryPanel !== button.dataset.panel;
            });
        });
    });

    function formatQuantity(value) {
        return new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 }).format(value);
    }

    function openConfirmation() {
        var quantityValid = validateWriteoff();
        var productValid = validateProduct();
        if (!quantityValid || !productValid) {
            var firstInvalid = !productValid ? input : (!quantityValid && quantityInput.getAttribute('aria-invalid') === 'true' ? quantityInput : justificationInput);
            firstInvalid.focus();
            return;
        }

        var quantity = Number(quantityInput.value);
        var reason = reasonSelect.options[reasonSelect.selectedIndex].text;
        var lot = lotInput.value.trim();
        var unit = selectedProduct.unidad || 'unidades';
        var remaining = selectedStock - quantity;
        confirmationTitle.textContent = '¿Confirmar esta baja?';
        confirmationIntro.textContent = 'Revisa el movimiento. El stock se verificará nuevamente antes de registrar la operación.';
        confirmationMovementLabel.textContent = 'Cantidad / motivo';
        confirmationLotRow.hidden = false;
        confirmationJustificationLabel.textContent = 'Justificación';
        confirmationNote.textContent = 'Se guardarán la baja, el nuevo stock y un único registro en Kardex. Durante el procesamiento, confirmar queda bloqueado para evitar reenvíos. No se alteran compras ni ventas anteriores.';
        confirmationSubmit.textContent = 'Confirmar baja';
        activeReviewButton = reviewButton;
        confirmationFields.product.textContent = selectedProduct.nombre || '---';
        confirmationFields.barcode.textContent = selectedProduct.codigo_barra || '---';
        confirmationFields.quantityReason.textContent = formatQuantity(quantity) + ' ' + unit + ' · ' + reason;
        confirmationFields.lot.textContent = lot ? lot + ' · Informativo' : 'Sin lote · No disponible';
        confirmationFields.equation.textContent =
            formatQuantity(selectedStock) + ' − ' + formatQuantity(quantity) + ' = ' + formatQuantity(remaining) + ' ' + unit;
        confirmationFields.balance.textContent =
            'Saldo anterior ' + formatQuantity(selectedStock) + ' · Salida ' + formatQuantity(quantity) + ' · Saldo previsto ' + formatQuantity(remaining);
        confirmationFields.justification.textContent = justificationInput.value.trim();
        confirmation.hidden = false;
        document.body.classList.add('inventory-confirmation-open');
        confirmationBack.focus();
    }

    function closeConfirmation() {
        confirmation.hidden = true;
        document.body.classList.remove('inventory-confirmation-open');
        activeReviewButton.focus();
    }

    function updateBalance() {
        if (selectedStock === null) {
            balance.before.textContent = '--- unidades';
            balance.deduction.textContent = '--- unidades';
            balance.after.textContent = '--- unidades';
            return;
        }
        var quantity = Number(quantityInput.value);
        if (!Number.isFinite(quantity) || quantity < 0) quantity = 0;
        balance.before.textContent = formatQuantity(selectedStock) + ' unidades';
        balance.deduction.textContent = '−' + formatQuantity(quantity) + ' unidades';
        balance.after.textContent = formatQuantity(selectedStock - quantity) + ' unidades';
    }

    function setValidationError(inputElement, errorElement, message) {
        errorElement.textContent = message;
        errorElement.hidden = !message;
        inputElement.setAttribute('aria-invalid', message ? 'true' : 'false');
        return !message;
    }

    function validateWriteoff() {
        var quantity = Number(quantityInput.value);
        var quantityMessage = '';
        if (!Number.isInteger(quantity) || quantity <= 0) {
            quantityMessage = 'Ingresa una cantidad entera mayor que 0.';
        } else if (selectedStock !== null && quantity > selectedStock) {
            quantityMessage = 'La cantidad no puede superar el stock disponible (' + formatQuantity(selectedStock) + ' unidades).';
        }
        var quantityValid = setValidationError(quantityInput, quantityError, quantityMessage);
        var justificationValid = setValidationError(
            justificationInput,
            justificationError,
            justificationInput.value.trim() ? '' : 'La justificación es obligatoria.'
        );
        return quantityValid && justificationValid;
    }

    function validateProduct() {
        var message = selectedProduct ? '' : 'Selecciona un producto para continuar con la baja.';
        setValidationError(input, productError, message);
        return !message;
    }

    function clearSummary() {
        summary.name.textContent = '---';
        summary.category.textContent = '---';
        summary.subcategory.textContent = '---';
        summary.stock.textContent = '---';
        selectedStock = null;
        selectedProduct = null;
        setValidationError(input, productError, '');
        updateBalance();
        if (validationAttempted) validateWriteoff();
    }

    function showMessage(message) {
        results.replaceChildren();
        var item = document.createElement('div');
        item.className = 'inventory-product-search-message';
        item.setAttribute('role', 'status');
        item.textContent = message;
        results.appendChild(item);
        results.hidden = false;
    }

    function selectProduct(product) {
        selectedProduct = product;
        input.value = product.nombre || '';
        summary.name.textContent = product.nombre || '---';
        summary.category.textContent = product.categoria || '---';
        summary.subcategory.textContent = 'Por implementar';
        var stock = Number(product.stock_actual);
        summary.stock.textContent = Number.isFinite(stock) ? stock + ' unidades' : '---';
        selectedStock = Number.isFinite(stock) ? stock : null;
        updateBalance();
        if (validationAttempted) validateWriteoff();
        results.hidden = true;
        results.replaceChildren();
    }

    function renderResults(products) {
        results.replaceChildren();
        if (!products.length) {
            showMessage('No se encontraron productos.');
            return;
        }

        products.forEach(function(product) {
            var option = document.createElement('button');
            option.type = 'button';
            option.className = 'inventory-product-option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = (product.codigo_barra || '') + ' · ' + (product.nombre || '');
            option.addEventListener('click', function() {
                selectProduct(product);
            });
            results.appendChild(option);
        });
        results.hidden = false;
    }

    async function searchProducts(query, sequence) {
        showMessage('Buscando productos…');
        try {
            var response = await Api.get('productos?q=' + encodeURIComponent(query) + '&limit=6');
            if (sequence !== searchSequence) return;
            if (!response || !response.ok || !response.data || response.data.status !== true) {
                showMessage((response && response.data && response.data.message) || 'No se pudieron cargar los productos.');
                return;
            }
            renderResults(Array.isArray(response.data.data) ? response.data.data : []);
        } catch (error) {
            if (sequence === searchSequence) {
                showMessage('Error al buscar productos: ' + error.message);
            }
        }
    }

    function resetWriteoff() {
        window.clearTimeout(searchTimer);
        searchSequence++;
        validationAttempted = false;
        input.value = '';
        quantityInput.value = '1';
        reasonSelect.selectedIndex = 0;
        justificationInput.value = '';
        lotInput.value = '';
        setValidationError(quantityInput, quantityError, '');
        setValidationError(justificationInput, justificationError, '');
        setValidationError(input, productError, '');
        clearSummary();
        results.replaceChildren();
        results.hidden = true;

        var bajaTab = document.querySelector('.inventory-adjustment-tab[data-panel="writeoff"]');
        tabs.forEach(function(tab) {
            var isSelected = tab === bajaTab;
            tab.classList.toggle('is-active', isSelected);
            tab.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        });
        description.textContent = bajaTab.dataset.description;
        document.querySelectorAll('[data-inventory-panel]').forEach(function(panel) {
            panel.hidden = panel.dataset.inventoryPanel !== 'writeoff';
        });
    }

    input.addEventListener('input', function() {
        window.clearTimeout(searchTimer);
        var query = input.value.trim();
        var sequence = ++searchSequence;
        clearSummary();
        if (!query) {
            results.hidden = true;
            results.replaceChildren();
            return;
        }
        searchTimer = window.setTimeout(function() {
            searchProducts(query, sequence);
        }, 250);
    });

    input.addEventListener('focus', function() {
        if (input.value.trim() && results.childElementCount) results.hidden = false;
    });

    quantityInput.addEventListener('input', updateBalance);
    quantityInput.addEventListener('input', function() {
        if (validationAttempted) validateWriteoff();
    });
    justificationInput.addEventListener('input', function() {
        if (validationAttempted) validateWriteoff();
    });
    reviewButton.addEventListener('click', function() {
        validationAttempted = true;
        openConfirmation();
    });
    cancelButton.addEventListener('click', resetWriteoff);
    confirmationClose.addEventListener('click', closeConfirmation);
    confirmationBack.addEventListener('click', closeConfirmation);
    confirmation.addEventListener('click', function(event) {
        if (event.target === confirmation) closeConfirmation();
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && !confirmation.hidden) closeConfirmation();
    });

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.inventory-product-search')) results.hidden = true;
    });

    initStockAdjustment();

    function initStockAdjustment() {
        var productInput = document.getElementById('stock-adjustment-product');
        if (!productInput) return;

        var productResults = document.getElementById('stock-adjustment-product-results');
        var productError = document.getElementById('stock-adjustment-product-error');
        var quantity = document.getElementById('stock-adjustment-quantity');
        var quantityError = document.getElementById('stock-adjustment-quantity-error');
        var justification = document.getElementById('stock-adjustment-justification');
        var justificationError = document.getElementById('stock-adjustment-justification-error');
        var cancel = document.getElementById('stock-adjustment-cancel-button');
        var review = document.getElementById('stock-adjustment-review-button');
        var directionInputs = document.querySelectorAll('input[name="stock_adjustment_direction"]');
        var selectedProduct = null;
        var selectedStock = null;
        var validationAttempted = false;
        var searchSequence = 0;
        var searchTimer;
        var summary = {
            name: document.getElementById('stock-adjustment-summary-name'),
            category: document.getElementById('stock-adjustment-summary-category'),
            subcategory: document.getElementById('stock-adjustment-summary-subcategory'),
            stock: document.getElementById('stock-adjustment-summary-stock')
        };
        var balance = {
            before: document.getElementById('stock-adjustment-balance-before'),
            changeLabel: document.getElementById('stock-adjustment-balance-change-label'),
            change: document.getElementById('stock-adjustment-balance-change'),
            after: document.getElementById('stock-adjustment-balance-after')
        };

        function getDirection() {
            return document.querySelector('input[name="stock_adjustment_direction"]:checked').value;
        }

        function updateBalance() {
            var direction = getDirection();
            var isEntry = direction === 'entry';
            balance.changeLabel.textContent = isEntry ? 'Entrada' : 'Salida';
            if (selectedStock === null) {
                balance.before.textContent = '--- unidades';
                balance.change.textContent = '--- unidades';
                balance.after.textContent = '--- unidades';
                return;
            }
            var amount = Number(quantity.value);
            if (!Number.isFinite(amount) || amount < 0) amount = 0;
            balance.before.textContent = formatQuantity(selectedStock) + ' unidades';
            balance.change.textContent = (isEntry ? '+' : '−') + formatQuantity(amount) + ' unidades';
            balance.after.textContent = formatQuantity(selectedStock + (isEntry ? amount : -amount)) + ' unidades';
        }

        function setError(inputElement, errorElement, message) {
            errorElement.textContent = message;
            errorElement.hidden = !message;
            inputElement.setAttribute('aria-invalid', message ? 'true' : 'false');
            return !message;
        }

        function validate() {
            var isEntry = getDirection() === 'entry';
            var amount = Number(quantity.value);
            var quantityMessage = '';
            if (!Number.isInteger(amount) || amount <= 0) {
                quantityMessage = 'Ingresa una cantidad entera mayor que 0.';
            } else if (!isEntry && selectedStock !== null && amount > selectedStock) {
                quantityMessage = 'La salida no puede superar el stock disponible (' + formatQuantity(selectedStock) + ' unidades).';
            }
            var productValid = setError(
                productInput,
                productError,
                selectedProduct ? '' : 'Selecciona un producto para continuar con el ajuste.'
            );
            var quantityValid = setError(quantity, quantityError, quantityMessage);
            var justificationValid = setError(
                justification,
                justificationError,
                justification.value.trim() ? '' : 'El motivo es obligatorio.'
            );
            return productValid && quantityValid && justificationValid;
        }

        function openAdjustmentConfirmation() {
            if (!validate()) {
                var invalidField = !selectedProduct ? productInput :
                    (quantity.getAttribute('aria-invalid') === 'true' ? quantity : justification);
                invalidField.focus();
                return;
            }

            var amount = Number(quantity.value);
            var isEntry = getDirection() === 'entry';
            var unit = selectedProduct.unidad || 'unidades';
            var balanceAfter = selectedStock + (isEntry ? amount : -amount);
            confirmationTitle.textContent = '¿Confirmar este ajuste?';
            confirmationIntro.textContent = 'Revisa el movimiento. El stock se verificará nuevamente antes de registrar la operación.';
            confirmationFields.product.textContent = selectedProduct.nombre || '---';
            confirmationFields.barcode.textContent = selectedProduct.codigo_barra || '---';
            confirmationMovementLabel.textContent = 'Sentido / cantidad';
            confirmationFields.quantityReason.textContent =
                (isEntry ? 'Entrada (+)' : 'Salida (−)') + ' · ' + formatQuantity(amount) + ' ' + unit;
            confirmationLotRow.hidden = true;
            confirmationFields.equation.textContent =
                formatQuantity(selectedStock) + (isEntry ? ' + ' : ' − ') + formatQuantity(amount) + ' = ' + formatQuantity(balanceAfter) + ' ' + unit;
            confirmationFields.balance.textContent =
                'Saldo anterior ' + formatQuantity(selectedStock) + ' · ' +
                (isEntry ? 'Entrada ' : 'Salida ') + formatQuantity(amount) + ' · Saldo previsto ' + formatQuantity(balanceAfter);
            confirmationJustificationLabel.textContent = 'Motivo';
            confirmationFields.justification.textContent = justification.value.trim();
            confirmationNote.textContent = 'Se guardarán el ajuste, el nuevo stock y un único registro en Kardex. Durante el procesamiento, confirmar queda bloqueado para evitar reenvíos.';
            confirmationSubmit.textContent = 'Confirmar ajuste';
            activeReviewButton = review;
            confirmation.hidden = false;
            document.body.classList.add('inventory-confirmation-open');
            confirmationBack.focus();
        }

        function showResultsMessage(message) {
            productResults.replaceChildren();
            var item = document.createElement('div');
            item.className = 'inventory-product-search-message';
            item.setAttribute('role', 'status');
            item.textContent = message;
            productResults.appendChild(item);
            productResults.hidden = false;
        }

        function selectAdjustmentProduct(product) {
            selectedProduct = product;
            productInput.value = product.nombre || '';
            summary.name.textContent = product.nombre || '---';
            summary.category.textContent = product.categoria || '---';
            summary.subcategory.textContent = 'Por implementar';
            selectedStock = Number(product.stock_actual);
            if (!Number.isFinite(selectedStock)) selectedStock = null;
            summary.stock.textContent = selectedStock === null ? '---' : formatQuantity(selectedStock) + ' unidades';
            productResults.replaceChildren();
            productResults.hidden = true;
            setError(productInput, productError, '');
            updateBalance();
            if (validationAttempted) validate();
        }

        async function searchAdjustmentProducts(query, sequence) {
            showResultsMessage('Buscando productos…');
            try {
                var response = await Api.get('productos?q=' + encodeURIComponent(query) + '&limit=6');
                if (sequence !== searchSequence) return;
                if (!response || !response.ok || !response.data || response.data.status !== true) {
                    showResultsMessage((response && response.data && response.data.message) || 'No se pudieron cargar los productos.');
                    return;
                }
                var products = Array.isArray(response.data.data) ? response.data.data : [];
                if (!products.length) {
                    showResultsMessage('No se encontraron productos.');
                    return;
                }
                productResults.replaceChildren();
                products.forEach(function(product) {
                    var option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'inventory-product-option';
                    option.setAttribute('role', 'option');
                    option.textContent = (product.codigo_barra || '') + ' · ' + (product.nombre || '');
                    option.addEventListener('click', function() {
                        selectAdjustmentProduct(product);
                    });
                    productResults.appendChild(option);
                });
                productResults.hidden = false;
            } catch (error) {
                if (sequence === searchSequence) {
                    showResultsMessage('Error al buscar productos: ' + error.message);
                }
            }
        }

        function reset() {
            window.clearTimeout(searchTimer);
            searchSequence++;
            validationAttempted = false;
            selectedProduct = null;
            selectedStock = null;
            productInput.value = '';
            quantity.value = '1';
            justification.value = '';
            directionInputs[0].checked = true;
            directionInputs.forEach(function(radio) {
                radio.closest('.inventory-direction-option').classList.toggle('is-selected', radio.checked);
            });
            Object.keys(summary).forEach(function(key) { summary[key].textContent = '---'; });
            setError(productInput, productError, '');
            setError(quantity, quantityError, '');
            setError(justification, justificationError, '');
            productResults.replaceChildren();
            productResults.hidden = true;
            updateBalance();
        }

        productInput.addEventListener('input', function() {
            window.clearTimeout(searchTimer);
            var query = productInput.value.trim();
            var sequence = ++searchSequence;
            selectedProduct = null;
            selectedStock = null;
            Object.keys(summary).forEach(function(key) { summary[key].textContent = '---'; });
            setError(productInput, productError, '');
            updateBalance();
            if (!query) {
                productResults.replaceChildren();
                productResults.hidden = true;
                return;
            }
            searchTimer = window.setTimeout(function() {
                searchAdjustmentProducts(query, sequence);
            }, 250);
        });

        productInput.addEventListener('focus', function() {
            if (productInput.value.trim() && productResults.childElementCount) productResults.hidden = false;
        });
        quantity.addEventListener('input', function() {
            updateBalance();
            if (validationAttempted) validate();
        });
        justification.addEventListener('input', function() {
            if (validationAttempted) validate();
        });
        directionInputs.forEach(function(radio) {
            radio.addEventListener('change', function() {
                directionInputs.forEach(function(item) {
                    item.closest('.inventory-direction-option').classList.toggle('is-selected', item.checked);
                });
                updateBalance();
                if (validationAttempted) validate();
            });
        });
        review.addEventListener('click', function() {
            validationAttempted = true;
            openAdjustmentConfirmation();
        });
        cancel.addEventListener('click', reset);
        document.addEventListener('click', function(event) {
            if (!event.target.closest('#stock-adjustment-product-results, #stock-adjustment-product')) {
                productResults.hidden = true;
            }
        });
    }
});
