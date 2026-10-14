/**
 * Angeo WebMCP for Magento 2 — registers store tools for in-browser AI agents.
 *
 * Plain script: no RequireJS, no Alpine, no build step, so it runs the same
 * on Luma and Hyvä. It does nothing in browsers without WebMCP.
 *
 * API: document.modelContext.registerTool(tool, { signal }) — the origin trial
 * shape (Chrome 149+). navigator.modelContext is the older entry point and is
 * used only as a fallback. Tools are removed by aborting the signal.
 *
 * @package Angeo_WebMcp
 * @license MIT
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else if (root && root.document) {
        api.boot(root);
    }
}(typeof window !== 'undefined' ? window : this, function () {
    'use strict';

    var DESCRIPTION_LIMIT = 2000;

    var PRODUCT_FIELDS =
        '__typename sku name url_key url_suffix stock_status ' +
        'price_range { minimum_price { final_price { value currency } regular_price { value currency } } } ' +
        'small_image { url label } ' +
        '... on ConfigurableProduct { configurable_options { attribute_code label values { label } } }';

    var SEARCH_QUERY =
        'query AngeoWebMcpSearch($q: String!, $n: Int!) {' +
        ' products(search: $q, pageSize: $n) { total_count items { ' + PRODUCT_FIELDS + ' } } }';

    var PRODUCT_QUERY =
        'query AngeoWebMcpProduct($sku: String!) {' +
        ' products(filter: { sku: { eq: $sku } }, pageSize: 1) { items { ' + PRODUCT_FIELDS +
        ' short_description { html } description { html } } } }';

    // ---------------------------------------------------------------- helpers

    function readConfig(doc) {
        var node = doc.getElementById('angeo-webmcp-config');
        if (!node) {
            return null;
        }
        try {
            return JSON.parse(node.textContent || '{}');
        } catch (e) {
            return null;
        }
    }

    function getModelContext(win) {
        var doc = win.document;
        if (doc && doc.modelContext && typeof doc.modelContext.registerTool === 'function') {
            return doc.modelContext;
        }
        var nav = win.navigator;
        if (nav && nav.modelContext && typeof nav.modelContext.registerTool === 'function') {
            return nav.modelContext;
        }
        return null;
    }

    function baseUrl(cfg) {
        return String(cfg.urls.graphql).replace(/graphql\/?$/, '');
    }

    function stripHtml(html) {
        return String(html || '')
            .replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ')
            .replace(/<[^>]+>/g, ' ')
            .replace(/&nbsp;/g, ' ')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"')
            .replace(/&#0?39;/g, "'")
            .replace(/\s+/g, ' ')
            .trim();
    }

    function truncate(text, limit) {
        return text.length > limit ? text.slice(0, limit - 1) + '…' : text;
    }

    function readCookie(doc, name) {
        var parts = String(doc.cookie || '').split(';');
        for (var i = 0; i < parts.length; i++) {
            var pair = parts[i].trim().split('=');
            if (pair[0] === name) {
                return decodeURIComponent(pair.slice(1).join('='));
            }
        }
        return '';
    }

    function getFormKey(win) {
        if (win.hyva && typeof win.hyva.getFormKey === 'function') {
            return win.hyva.getFormKey();
        }
        var fromCookie = readCookie(win.document, 'form_key');
        if (fromCookie) {
            return fromCookie;
        }
        var input = win.document.querySelector('input[name="form_key"]');
        return input ? input.value : '';
    }

    /** Ask the theme to refresh the mini cart. */
    function reloadCartSection(win) {
        try {
            if (win.hyva) {
                win.dispatchEvent(new win.CustomEvent('reload-customer-section-data'));
            } else if (typeof win.require === 'function') {
                win.require(['Magento_Customer/js/customer-data'], function (customerData) {
                    customerData.reload(['cart'], true);
                });
            }
        } catch (e) {
            // A stale mini cart is not worth failing the tool call for.
        }
    }

    function toProduct(cfg, item) {
        var price = item.price_range && item.price_range.minimum_price;
        var product = {
            sku: item.sku,
            name: item.name,
            type: item.__typename,
            url: item.url_key ? baseUrl(cfg) + item.url_key + (item.url_suffix || '') : null,
            in_stock: item.stock_status === 'IN_STOCK',
            price: price && price.final_price ? price.final_price.value : null,
            regular_price: price && price.regular_price ? price.regular_price.value : null,
            currency: price && price.final_price ? price.final_price.currency : cfg.currency,
            image: item.small_image ? item.small_image.url : null
        };
        if (item.configurable_options) {
            product.options = item.configurable_options.map(function (option) {
                return {
                    code: option.attribute_code,
                    label: option.label,
                    values: (option.values || []).map(function (v) { return v.label; })
                };
            });
        }
        return product;
    }

    function result(data) {
        return JSON.stringify(data);
    }

    // ------------------------------------------------------------- transport

    function createClient(win, cfg) {
        var fetchImpl = win.fetch.bind(win);

        function graphql(query, variables) {
            return fetchImpl(cfg.urls.graphql, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Store': cfg.storeCode
                },
                body: JSON.stringify({ query: query, variables: variables })
            }).then(function (response) {
                return response.json();
            }).then(function (body) {
                if (body.errors && body.errors.length) {
                    throw new Error(body.errors[0].message || 'GraphQL error');
                }
                return body.data;
            });
        }

        function postForm(url, fields) {
            var body = new win.URLSearchParams();
            Object.keys(fields).forEach(function (key) {
                body.append(key, fields[key]);
            });
            return fetchImpl(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            }).then(readJson);
        }

        function getJson(url) {
            return fetchImpl(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(readJson);
        }

        function readJson(response) {
            return response.json().catch(function () {
                return { ok: false, error: 'http_' + response.status };
            });
        }

        return { graphql: graphql, postForm: postForm, getJson: getJson };
    }

    // ----------------------------------------------------------------- tools

    function buildTools(win, cfg, client) {
        var enabled = {};
        (cfg.tools || []).forEach(function (name) { enabled[name] = true; });
        var store = cfg.storeName || 'this store';
        var tools = [];

        if (enabled.search_products) {
            tools.push({
                name: 'search_products',
                title: 'Search ' + store,
                description: 'Search the ' + store + ' catalog by keywords. Returns SKU, name, live price, ' +
                    'stock, product URL and, for products with variants, the options to choose. ' +
                    'Use the SKU with get_product or add_to_cart.',
                inputSchema: {
                    type: 'object',
                    properties: {
                        query: { type: 'string', minLength: 1, maxLength: 200, description: 'Search words, e.g. "grey backpack"' },
                        page_size: { type: 'integer', minimum: 1, maximum: cfg.maxResults, description: 'How many results, at most ' + cfg.maxResults },
                        in_stock_only: { type: 'boolean', description: 'Leave out products that are out of stock' }
                    },
                    required: ['query']
                },
                annotations: { readOnlyHint: true, untrustedContentHint: true },
                execute: function (input) {
                    var size = Math.min(Math.max(parseInt(input.page_size, 10) || cfg.maxResults, 1), cfg.maxResults);
                    var query = String(input.query || '').trim().slice(0, 200);
                    if (!query) {
                        return Promise.resolve(result({ ok: false, error: 'query_required' }));
                    }
                    return client.graphql(SEARCH_QUERY, { q: query, n: size }).then(function (data) {
                        var items = (data.products.items || []).map(function (item) { return toProduct(cfg, item); });
                        if (input.in_stock_only) {
                            items = items.filter(function (p) { return p.in_stock; });
                        }
                        return result({ ok: true, total_count: data.products.total_count, products: items });
                    });
                }
            });
        }

        if (enabled.get_product) {
            tools.push({
                name: 'get_product',
                title: 'Product details',
                description: 'Get full details of one ' + store + ' product by SKU: price, stock, ' +
                    'description and variant options.',
                inputSchema: {
                    type: 'object',
                    properties: {
                        sku: { type: 'string', minLength: 1, maxLength: 64, description: 'Product SKU from search_products' }
                    },
                    required: ['sku']
                },
                annotations: { readOnlyHint: true, untrustedContentHint: true },
                execute: function (input) {
                    var sku = String(input.sku || '').trim();
                    return client.graphql(PRODUCT_QUERY, { sku: sku }).then(function (data) {
                        var item = (data.products.items || [])[0];
                        if (!item) {
                            return result({ ok: false, error: 'not_found', message: 'No product with SKU "' + sku + '".' });
                        }
                        var product = toProduct(cfg, item);
                        product.short_description = truncate(stripHtml(item.short_description && item.short_description.html), 500);
                        product.description = truncate(stripHtml(item.description && item.description.html), DESCRIPTION_LIMIT);
                        return result({ ok: true, product: product });
                    });
                }
            });
        }

        if (enabled.cart) {
            tools.push({
                name: 'add_to_cart',
                title: 'Add to cart',
                description: 'Add a product to the shopper\'s own cart on ' + store + '. For products with ' +
                    'variants, pass every option, e.g. {"color": "Blue", "size": "M"}. If an option is ' +
                    'missing, the answer lists the allowed values. Does not pay or place an order.',
                inputSchema: {
                    type: 'object',
                    properties: {
                        sku: { type: 'string', minLength: 1, maxLength: 64, description: 'Product SKU (the parent SKU for products with variants)' },
                        qty: { type: 'number', exclusiveMinimum: 0, maximum: cfg.maxQty, description: 'Quantity, default 1' },
                        options: {
                            type: 'object',
                            description: 'Variant choices by option code or label',
                            additionalProperties: { type: 'string' }
                        }
                    },
                    required: ['sku']
                },
                annotations: { readOnlyHint: false },
                execute: function (input) {
                    return client.postForm(cfg.urls.cartAdd, {
                        sku: String(input.sku || ''),
                        qty: String(input.qty || 1),
                        options: JSON.stringify(input.options || {}),
                        form_key: getFormKey(win)
                    }).then(function (response) {
                        if (response && response.ok) {
                            reloadCartSection(win);
                        }
                        return result(response);
                    });
                }
            });

            tools.push({
                name: 'get_cart',
                title: 'View cart',
                description: 'Show what is in the shopper\'s cart on ' + store + ': items, quantities and totals.',
                inputSchema: { type: 'object', properties: {} },
                annotations: { readOnlyHint: true },
                execute: function () {
                    return client.getJson(cfg.urls.cartView).then(result);
                }
            });
        }

        if (enabled.go_to_checkout) {
            tools.push({
                name: 'go_to_checkout',
                title: 'Go to checkout',
                description: 'Open the ' + store + ' checkout page so the shopper can review the cart, ' +
                    'enter details and pay. The shopper completes payment; the agent does not.',
                inputSchema: { type: 'object', properties: {} },
                annotations: { readOnlyHint: false },
                execute: function () {
                    win.setTimeout(function () { win.location.assign(cfg.urls.checkout); }, 0);
                    return Promise.resolve(result({ ok: true, message: 'Opening checkout.', url: cfg.urls.checkout }));
                }
            });
        }

        return tools;
    }

    // ------------------------------------------------------------------ boot

    function register(modelContext, tools, signal) {
        return Promise.all(tools.map(function (tool) {
            return Promise.resolve()
                .then(function () { return modelContext.registerTool(tool, { signal: signal }); })
                .then(function () { return tool.name; })
                .catch(function (error) {
                    if (typeof console !== 'undefined') {
                        console.warn('[Angeo WebMCP] could not register ' + tool.name, error);
                    }
                    return null;
                });
        })).then(function (names) {
            return names.filter(Boolean);
        });
    }

    function boot(win) {
        var cfg = readConfig(win.document);
        var modelContext = getModelContext(win);
        if (!cfg || !modelContext || typeof win.fetch !== 'function') {
            return Promise.resolve([]);
        }
        var controller = new win.AbortController();
        var tools = buildTools(win, cfg, createClient(win, cfg));
        return register(modelContext, tools, controller.signal).then(function (names) {
            win.angeoWebMcp = {
                version: cfg.version,
                tools: names,
                unregister: function () { controller.abort(); }
            };
            return names;
        });
    }

    return {
        boot: boot,
        buildTools: buildTools,
        createClient: createClient,
        getModelContext: getModelContext,
        readConfig: readConfig,
        stripHtml: stripHtml,
        toProduct: toProduct
    };
}));
