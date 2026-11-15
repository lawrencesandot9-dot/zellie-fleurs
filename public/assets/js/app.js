/* Zellie Fleurs — cart, checkout and admin interactions */
(() => {
    'use strict';

    const CART_KEY = 'zf_cart';
    const MAX_QTY = 99;
    let productMap = new Map();
    let productsPromise = null;
    // Set while the checkout page is open so a cart-drawer retry after a
    // failed products fetch also re-renders the checkout summary.
    let checkoutRerender = null;
    let cart = readCart();

    function readCart() {
        try {
            const raw = localStorage.getItem(CART_KEY);
            if (!raw) return [];
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) return [];

            const merged = new Map();
            parsed.forEach((item) => {
                const id = Number(item && item.id);
                const qty = Number(item && item.qty);
                if (!Number.isInteger(id) || id <= 0 || !Number.isInteger(qty) || qty <= 0) return;
                merged.set(id, Math.min(MAX_QTY, (merged.get(id) || 0) + qty));
            });

            return Array.from(merged, ([id, qty]) => ({ id, qty }));
        } catch (error) {
            return [];
        }
    }

    function writeCart() {
        try {
            localStorage.setItem(CART_KEY, JSON.stringify(cart));
        } catch (error) {
            /* Storage can be unavailable in private browsing; the in-memory cart still works. */
        }
        updateBadge();
    }

    function money(value) {
        const number = Number(value) || 0;
        return '₱' + number.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function cartCount() {
        return cart.reduce((sum, item) => sum + item.qty, 0);
    }

    function subtotal() {
        return cart.reduce((sum, item) => {
            const product = productMap.get(item.id);
            return product ? sum + Number(product.price) * item.qty : sum;
        }, 0);
    }

    function updateBadge() {
        document.querySelectorAll('[data-cart-badge]').forEach((badge) => {
            badge.textContent = String(cartCount());
        });
    }

    function loadProducts(force) {
        if (force) productsPromise = null;
        if (productsPromise) return productsPromise;

        productsPromise = fetch('/api/products.php', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) throw new Error('Product request failed');
                return response.json();
            })
            .then((data) => {
                if (!data || !Array.isArray(data.products)) throw new Error('Invalid product data');
                productMap = new Map(data.products.map((product) => [Number(product.id), product]));
                normalizeCart();
                return productMap;
            })
            .catch(() => {
                productsPromise = null;
                return productMap;
            });

        return productsPromise;
    }

    function normalizeCart() {
        if (productMap.size === 0) return;

        let changed = false;
        const next = [];

        cart.forEach((item) => {
            const product = productMap.get(item.id);
            if (!product || !product.available || Number(product.stock_quantity) <= 0) {
                changed = true;
                return;
            }
            const stock = Number(product.stock_quantity);
            const qty = Math.min(item.qty, stock, MAX_QTY);
            if (qty !== item.qty) changed = true;
            if (qty > 0) next.push({ id: item.id, qty });
        });

        if (changed) {
            cart = next;
            writeCart();
        } else {
            cart = next;
        }
    }

    function addToCart(id, qty = 1) {
        const product = productMap.get(Number(id));
        if (!product) {
            toast('That arrangement is no longer available.', 'error');
            return;
        }
        if (!product.available || Number(product.stock_quantity) <= 0) {
            toast('This arrangement is currently sold out.', 'error');
            return;
        }

        const stock = Number(product.stock_quantity);
        const wanted = Number.isInteger(qty) && qty > 0 ? qty : 1;
        const existing = cart.find((item) => item.id === product.id);
        const currentQty = existing ? existing.qty : 0;
        if (currentQty >= stock) {
            toast('Only ' + product.stock_quantity + ' available for this arrangement.', 'error');
            return;
        }

        const addQty = Math.min(wanted, stock - currentQty, MAX_QTY);
        if (addQty < wanted) {
            toast('Only ' + product.stock_quantity + ' available — added those.', 'error');
        }
        if (existing) {
            existing.qty = Math.min(existing.qty + addQty, stock, MAX_QTY);
        } else {
            cart.push({ id: product.id, qty: addQty });
        }

        writeCart();
        renderCart();
        openCart();
        toast(product.name + ' added to your cart.', 'success');
    }

    function updateQty(id, delta) {
        const item = cart.find((entry) => entry.id === Number(id));
        const product = productMap.get(Number(id));
        if (!item || !product) return;

        const nextQty = item.qty + delta;
        if (nextQty <= 0) {
            cart = cart.filter((entry) => entry.id !== item.id);
        } else if (nextQty > Number(product.stock_quantity)) {
            toast('Only ' + product.stock_quantity + ' available for this arrangement.', 'error');
            return;
        } else {
            item.qty = Math.min(nextQty, MAX_QTY);
        }

        writeCart();
        renderCart();
    }

    function removeItem(id) {
        cart = cart.filter((entry) => entry.id !== Number(id));
        writeCart();
        renderCart();
    }

    function productsUnavailableMarkup() {
        return `
            <div class="empty-state">
                <img src="/assets/img/logo-mark.svg" alt="" width="48" height="48">
                <h3>We could not load the shop</h3>
                <p>Your selection is still saved, but checkout needs a quick reload. Try again.</p>
                <button class="btn btn--soft" type="button" data-cart-retry>Retry</button>
            </div>`;
    }

    function emptyCartMarkup() {
        return `
            <div class="empty-state">
                <img src="/assets/img/logo-mark.svg" alt="" width="48" height="48">
                <h3>Your cart is empty</h3>
                <p>Add a bouquet or keepsake box to begin your order.</p>
                <button class="btn btn--soft" type="button" data-cart-close>Browse the collection</button>
            </div>`;
    }

    function emptyCartPageMarkup() {
        return `
            <div class="empty-state">
                <img src="/assets/img/logo-mark.svg" alt="" width="48" height="48">
                <h3>Your cart is empty</h3>
                <p>Add a bouquet or keepsake box to begin your order.</p>
                <a class="btn btn--soft" href="/index.php#shop">Browse the collection</a>
            </div>`;
    }

    function setCheckoutDisabled(checkoutLink, disabled) {
        if (!checkoutLink) return;
        checkoutLink.classList.toggle('is-disabled', disabled);
        if (disabled) {
            checkoutLink.setAttribute('aria-disabled', 'true');
        } else {
            checkoutLink.removeAttribute('aria-disabled');
        }
    }

    function setCheckoutSubmitDisabled(submit, disabled) {
        if (!submit) return;
        submit.disabled = disabled;
        // Mirror the existing .btn.is-disabled visual state used elsewhere.
        submit.classList.toggle('is-disabled', disabled);
        if (disabled) {
            submit.setAttribute('aria-disabled', 'true');
        } else {
            submit.removeAttribute('aria-disabled');
        }
    }

    function renderCartInto(container, subtotalEl, checkoutLink, emptyMarkup) {
        if (!container) return;

        if (cart.length === 0) {
            container.innerHTML = emptyMarkup;
            if (subtotalEl) subtotalEl.textContent = money(0);
            setCheckoutDisabled(checkoutLink, true);
            return;
        }

        const rows = cart.map((item) => {
            const product = productMap.get(item.id);
            if (!product) return '';
            const lineTotal = Number(product.price) * item.qty;            return `
                <div class="cart-item">
                    <img class="cart-item__image" src="${escapeHtml(product.image_url)}" alt="" width="62" height="76" loading="lazy" onerror="this.onerror=null;this.src='/assets/img/flower-placeholder.svg';">
                    <div class="cart-item__info">
                        <h3>${escapeHtml(product.name)}</h3>
                        <span class="muted">${escapeHtml(product.category)}</span>
                        <div class="cart-item__price">${money(lineTotal)}</div>
                        <button class="cart-item__remove" type="button" data-cart-remove="${item.id}">Remove</button>
                    </div>
                    <div class="qty-control" aria-label="Quantity for ${escapeHtml(product.name)}">
                        <button type="button" data-cart-dec="${item.id}" aria-label="Decrease quantity">−</button>
                        <span>${item.qty}</span>
                        <button type="button" data-cart-inc="${item.id}" aria-label="Increase quantity">+</button>
                    </div>
                </div>`;
        }).join('');

        const itemsLoaded = productMap.size > 0;
        if (rows === '') {
            container.innerHTML = itemsLoaded ? emptyMarkup : productsUnavailableMarkup();
            if (subtotalEl) subtotalEl.textContent = itemsLoaded ? money(0) : subtotalEl.textContent;
            setCheckoutDisabled(checkoutLink, true);
            return;
        }

        container.innerHTML = rows;

        if (subtotalEl) subtotalEl.textContent = money(subtotal());
        setCheckoutDisabled(checkoutLink, !rows);
    }

    function renderCart() {
        renderCartInto(
            document.querySelector('[data-cart-items]'),
            document.querySelector('[data-cart-subtotal]'),
            document.querySelector('[data-cart-checkout]'),
            emptyCartMarkup(),
        );
        renderCartInto(
            document.querySelector('[data-cart-page-items]'),
            document.querySelector('[data-cart-page-subtotal]'),
            document.querySelector('[data-cart-page-link]'),
            emptyCartPageMarkup(),
        );
    }

    function getMobileMenu() {
        return {
            toggle: document.querySelector('[data-menu-toggle]'),
            nav: document.querySelector('[data-mobile-nav]'),
            scrim: document.querySelector('[data-menu-scrim]'),
        };
    }

    function setMenuOpen(open, options = {}) {
        const { toggle, nav, scrim } = getMobileMenu();
        if (!toggle || !nav) return;

        if (open) {
            nav.removeAttribute('hidden');
        } else {
            nav.setAttribute('hidden', '');
        }

        nav.classList.toggle('is-open', open);
        toggle.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        if (scrim) scrim.hidden = !open;
        document.body.classList.toggle('menu-open', open);

        if (!open && options.returnFocus) {
            toggle.focus();
        }
    }

    function closeMobileMenu(returnFocus = false) {
        setMenuOpen(false, { returnFocus });
    }

    function openCart() {
        closeMobileMenu(false);
        const drawer = document.querySelector('[data-cart-drawer]');
        const overlay = document.querySelector('[data-cart-overlay]');
        if (!drawer) return;

        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        if (overlay) overlay.hidden = false;
        document.body.classList.add('cart-open');
        renderCart();
    }

    function closeCart() {
        const drawer = document.querySelector('[data-cart-drawer]');
        const overlay = document.querySelector('[data-cart-overlay]');
        if (!drawer) return;

        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        if (overlay) overlay.hidden = true;
        document.body.classList.remove('cart-open');
    }

    function toast(message, type) {
        const region = document.querySelector('[data-toast-region]');
        if (!region) return;

        const el = document.createElement('div');
        el.className = 'toast' + (type ? ' toast--' + type : '');
        el.textContent = message;
        region.appendChild(el);
        window.setTimeout(() => {
            el.remove();
        }, 3600);
    }

    function setupCartUi() {
        document.addEventListener('click', (event) => {
            const open = event.target.closest('[data-cart-open]');
            if (open) {
                event.preventDefault();
                openCart();
                return;
            }

            const close = event.target.closest('[data-cart-close]');
            if (close) {
                event.preventDefault();
                closeCart();
                return;
            }

            const inc = event.target.closest('[data-cart-inc]');
            if (inc) {
                updateQty(inc.getAttribute('data-cart-inc'), 1);
                return;
            }

            const dec = event.target.closest('[data-cart-dec]');
            if (dec) {
                updateQty(dec.getAttribute('data-cart-dec'), -1);
                return;
            }

            const remove = event.target.closest('[data-cart-remove]');
            if (remove) {
                removeItem(remove.getAttribute('data-cart-remove'));
                return;
            }

            const retry = event.target.closest('[data-cart-retry]');
            if (retry) {
                loadProducts(true).then(() => {
                    renderCart();
                    if (checkoutRerender) checkoutRerender();
                });
            }
        });

        const overlay = document.querySelector('[data-cart-overlay]');
        if (overlay) overlay.addEventListener('click', closeCart);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeCart();
        });

        document.querySelectorAll('[data-add-to-cart]').forEach((button) => {
            button.addEventListener('click', async () => {
                await loadProducts();

                let qty = 1;
                const qtyInput = button.parentElement
                    ? button.parentElement.querySelector('[data-qty-input]')
                    : null;
                if (qtyInput) {
                    const parsed = Math.floor(Number(qtyInput.value));
                    if (Number.isFinite(parsed) && parsed > 0) qty = parsed;
                }

                addToCart(button.getAttribute('data-product-id'), qty);
            });
        });

        updateBadge();
        renderCart();
        loadProducts().then(renderCart);
    }

    function setupQtySteppers() {
        document.querySelectorAll('.qty-stepper').forEach((stepper) => {
            const input = stepper.querySelector('[data-qty-input]');
            if (!input) return;

            const maxQty = () => {
                const declared = Number(stepper.getAttribute('data-max-qty')) || Number(input.max) || MAX_QTY;
                return Math.max(1, Math.min(declared, MAX_QTY));
            };

            const clamp = () => {
                let value = Math.floor(Number(input.value) || 1);
                input.value = String(Math.max(1, Math.min(value, maxQty())));
            };

            const step = function (delta) {
                let value = Math.floor(Number(input.value) || 1) + delta;
                input.value = String(Math.max(1, Math.min(value, maxQty())));
            };

            input.addEventListener('change', clamp);
            stepper.querySelectorAll('[data-qty-dec]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (!button.disabled) step(-1);
                });
            });
            stepper.querySelectorAll('[data-qty-inc]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (!button.disabled) step(1);
                });
            });
        });
    }

    function setupCopyCode() {
        const button = document.querySelector('[data-copy-code]');
        if (!button) return;

        button.addEventListener('click', async () => {
            const code = button.getAttribute('data-copy-code') || '';
            try {
                if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                    throw new Error('unavailable');
                }
                await navigator.clipboard.writeText(code);
                toast('Order code copied', 'success');
            } catch (error) {
                toast('Copy not available', 'error');
            }
        });
    }

    function setupMenu() {
        const { toggle, nav, scrim } = getMobileMenu();
        if (!toggle || !nav) return;

        toggle.addEventListener('click', () => {
            setMenuOpen(nav.hasAttribute('hidden'));
        });

        nav.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => closeMobileMenu(false));
        });

        if (scrim) {
            scrim.addEventListener('click', () => closeMobileMenu(false));
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !nav.hasAttribute('hidden')) {
                closeMobileMenu(true);
            }
        });

        document.addEventListener('click', (event) => {
            if (nav.hasAttribute('hidden')) return;
            if (nav.contains(event.target) || toggle.contains(event.target)) return;
            closeMobileMenu(false);
        });

        const desktop = window.matchMedia('(min-width: 900px)');
        const handleDesktop = () => {
            if (desktop.matches) closeMobileMenu(false);
        };
        if (typeof desktop.addEventListener === 'function') {
            desktop.addEventListener('change', handleDesktop);
        } else if (typeof desktop.addListener === 'function') {
            desktop.addListener(handleDesktop);
        }
    }

    function setupPaymentToggle() {
        const options = document.querySelectorAll('[data-payment-option]');
        const gcashBox = document.querySelector('[data-gcash-box]');
        const gcashRef = document.getElementById('gcash_ref');

        function apply(method) {
            options.forEach((option) => {
                const isSelected = option.getAttribute('data-payment-option') === method;
                option.classList.toggle('is-selected', isSelected);
                const radio = option.querySelector('input[type="radio"]');
                if (radio) radio.checked = isSelected;
            });
            if (gcashBox) gcashBox.classList.toggle('is-hidden', method !== 'GCash');
            if (gcashRef) gcashRef.required = method === 'GCash';
        }

        options.forEach((option) => {
            const radio = option.querySelector('input[type="radio"]');
            if (!radio) return;
            radio.addEventListener('change', () => {
                if (radio.checked) apply(radio.value);
            });
        });

        const checked = document.querySelector('input[name="payment_method"]:checked');
        if (checked) apply(checked.value);
    }

    function setupCheckout() {
        const page = document.querySelector('[data-checkout-page]');
        if (!page) return;

        const form = document.querySelector('[data-checkout-form]');
        const summary = document.querySelector('[data-cart-summary]');
        const totalEl = document.querySelector('[data-checkout-total]');
        const jsonInput = document.querySelector('[data-cart-json]');
        const submit = document.querySelector('[data-checkout-submit]');

        function renderCheckout() {
            if (!summary) return;

            if (cart.length === 0) {
                summary.innerHTML = `
                    <div class="empty-state">
                        <img src="/assets/img/logo-mark.svg" alt="" width="42" height="42">
                        <h3>Your cart is empty</h3>
                        <p>Add an arrangement before checking out.</p>
                        <a class="btn btn--soft" href="/index.php#shop">Browse the collection</a>
                    </div>`;
                if (totalEl) totalEl.textContent = money(0);
                setCheckoutSubmitDisabled(submit, true);
                if (jsonInput) jsonInput.value = '[]';
                return;
            }

            if (productMap.size === 0) {
                // Products API failed: no prices, no trustworthy summary.
                // Show the same retry panel the cart drawer uses and keep
                // the submit disabled (items_json stays untouched at '[]').
                summary.innerHTML = productsUnavailableMarkup();
                setCheckoutSubmitDisabled(submit, true);
                return;
            }

            summary.innerHTML = cart.map((item) => {
                const product = productMap.get(item.id);
                if (!product) return '';
                return `
                    <div class="summary-line">
                        <span><strong>${item.qty}×</strong> ${escapeHtml(product.name)}</span>
                        <span>${money(Number(product.price) * item.qty)}</span>
                    </div>`;
            }).join('');

            if (totalEl) totalEl.textContent = money(subtotal());
            setCheckoutSubmitDisabled(submit, false);
            if (jsonInput) jsonInput.value = JSON.stringify(cart.map((item) => ({ id: item.id, qty: item.qty })));
        }

        checkoutRerender = renderCheckout;

        loadProducts().then(renderCheckout);
        updateBadge();

        if (form) {
            form.addEventListener('submit', (event) => {
                if (cart.length === 0) {
                    event.preventDefault();
                    toast('Your cart is empty. Please add an arrangement first.', 'error');
                    return;
                }

                if (productMap.size === 0) {
                    // Products never loaded: keep the customer from confirming
                    // an order they cannot see priced. (Server totals are
                    // recomputed safely, but the summary would be blank.)
                    event.preventDefault();
                    toast('We could not load the shop yet. Please try again in a moment.', 'error');
                    return;
                }

                if (jsonInput) {
                    jsonInput.value = JSON.stringify(cart.map((item) => ({ id: item.id, qty: item.qty })));
                }

                if (submit) {
                    submit.disabled = true;
                    submit.textContent = 'Placing your order…';
                }
            });
        }
    }

    function setupConfirmation() {
        if (!document.querySelector('[data-order-success]')) return;
        try {
            localStorage.removeItem(CART_KEY);
        } catch (error) {
            /* ignore */
        }
        cart = [];
        writeCart();
        renderCart();
    }

    function setupAdmin() {
        document.querySelectorAll('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                const message = form.getAttribute('data-confirm') || 'Are you sure?';
                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });

        document.querySelectorAll('form[data-status-form]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                const select = form.querySelector('select[name="status"]');
                if (!select) return;
                const previous = select.getAttribute('data-prev') || '';
                if (select.value === 'Cancelled' && previous !== 'Cancelled') {
                    if (!window.confirm('Cancel this order? The ordered quantities will be returned to stock and the order will no longer count toward revenue.')) {
                        event.preventDefault();
                        select.value = previous;
                    }
                }
            });
        });

        const imageInput = document.querySelector('[data-image-input]');
        const imagePreview = document.querySelector('[data-image-preview]');
        if (imageInput && imagePreview) {
            imageInput.addEventListener('change', () => {
                const file = imageInput.files && imageInput.files[0];
                if (!file) return;
                const url = URL.createObjectURL(file);
                imagePreview.src = url;
                imagePreview.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        setupCartUi();
        setupMenu();
        setupPaymentToggle();
        setupCheckout();
        setupConfirmation();
        setupAdmin();
        setupQtySteppers();
        setupCopyCode();
    });
})();
