/* Run: node --test Test/js */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const api = require('../../view/frontend/web/js/webmcp.js');

const CONFIG = {
    version: '1.0.0',
    tools: ['search_products', 'get_product', 'cart', 'go_to_checkout'],
    storeCode: 'default',
    storeName: 'Demo Store',
    currency: 'USD',
    maxResults: 10,
    maxQty: 100,
    urls: {
        graphql: 'https://shop.test/graphql',
        cartAdd: 'https://shop.test/webmcp/cart/add/',
        cartView: 'https://shop.test/webmcp/cart/view/',
        cart: 'https://shop.test/checkout/cart/',
        checkout: 'https://shop.test/checkout/'
    }
};

function fakeWindow({ withContext = true, legacy = false, hyva = false, responses = {} } = {}) {
    const registered = [];
    const calls = [];
    const events = [];
    const modelContext = {
        registerTool(tool, options) {
            if (registered.some((t) => t.tool.name === tool.name)) {
                throw new Error('duplicate');
            }
            registered.push({ tool, options });
        }
    };
    const doc = {
        cookie: 'foo=1; form_key=KEY123',
        getElementById: (id) => (id === 'angeo-webmcp-config' ? { textContent: JSON.stringify(CONFIG) } : null),
        querySelector: () => null
    };
    if (withContext && !legacy) {
        doc.modelContext = modelContext;
    }
    const win = {
        document: doc,
        navigator: withContext && legacy ? { modelContext } : {},
        URLSearchParams,
        AbortController,
        CustomEvent: class { constructor(type) { this.type = type; } },
        dispatchEvent: (e) => events.push(e.type),
        setTimeout: (fn) => fn(),
        location: { assigned: null, assign(url) { this.assigned = url; } },
        fetch: async (url, init = {}) => {
            calls.push({ url, init });
            const handler = responses[url];
            const body = typeof handler === 'function' ? handler(init) : handler;
            return { status: 200, json: async () => body };
        }
    };
    if (hyva) {
        win.hyva = { getFormKey: () => 'HYVAKEY' };
    }
    return { win, registered, calls, events };
}

const SEARCH_RESPONSE = {
    data: {
        products: {
            total_count: 2,
            items: [
                {
                    __typename: 'SimpleProduct', sku: '24-MB02', name: 'Fusion Backpack', url_key: 'fusion-backpack',
                    url_suffix: '.html', stock_status: 'IN_STOCK',
                    price_range: { minimum_price: { final_price: { value: 59, currency: 'USD' }, regular_price: { value: 59, currency: 'USD' } } },
                    small_image: { url: 'https://shop.test/img.jpg', label: 'x' }
                },
                {
                    __typename: 'ConfigurableProduct', sku: 'MH01', name: 'Hoodie', url_key: 'hoodie', url_suffix: '.html',
                    stock_status: 'OUT_OF_STOCK',
                    price_range: { minimum_price: { final_price: { value: 52, currency: 'USD' }, regular_price: { value: 52, currency: 'USD' } } },
                    small_image: null,
                    configurable_options: [{ attribute_code: 'size', label: 'Size', values: [{ label: 'S' }, { label: 'M' }] }]
                }
            ]
        }
    }
};

test('does nothing without WebMCP', async () => {
    const { win, registered } = fakeWindow({ withContext: false });
    assert.deepEqual(await api.boot(win), []);
    assert.equal(registered.length, 0);
});

test('registers all five tools on document.modelContext with a shared signal', async () => {
    const { win, registered } = fakeWindow();
    const names = await api.boot(win);
    assert.deepEqual(names, ['search_products', 'get_product', 'add_to_cart', 'get_cart', 'go_to_checkout']);
    const signal = registered[0].options.signal;
    assert.ok(registered.every((r) => r.options.signal === signal));
    for (const { tool } of registered) {
        assert.equal(typeof tool.execute, 'function');
        assert.equal(tool.inputSchema.type, 'object');
        assert.ok(tool.description.length > 20);
    }
    assert.deepEqual(win.angeoWebMcp.tools, names);
});

test('falls back to navigator.modelContext', async () => {
    const { win } = fakeWindow({ legacy: true });
    assert.equal((await api.boot(win)).length, 5);
});

test('respects disabled tools', () => {
    const { win } = fakeWindow();
    const cfg = { ...CONFIG, tools: ['search_products'] };
    const tools = api.buildTools(win, cfg, api.createClient(win, cfg));
    assert.deepEqual(tools.map((t) => t.name), ['search_products']);
});

test('search_products maps GraphQL, caps page size, filters stock', async () => {
    const { win, calls } = fakeWindow({ responses: { [CONFIG.urls.graphql]: SEARCH_RESPONSE } });
    const tools = api.buildTools(win, CONFIG, api.createClient(win, CONFIG));
    const search = tools.find((t) => t.name === 'search_products');
    const out = JSON.parse(await search.execute({ query: 'backpack', page_size: 999, in_stock_only: true }));
    assert.equal(out.ok, true);
    assert.equal(out.products.length, 1);
    assert.equal(out.products[0].url, 'https://shop.test/fusion-backpack.html');
    assert.equal(out.products[0].price, 59);
    const sent = JSON.parse(calls[0].init.body);
    assert.equal(sent.variables.n, 10);
    assert.equal(calls[0].init.headers.Store, 'default');
    assert.equal(calls[0].init.headers['X-Angeo-Agent-Tool'], 'search_products');
});

test('search_products exposes variant options', async () => {
    const { win } = fakeWindow({ responses: { [CONFIG.urls.graphql]: SEARCH_RESPONSE } });
    const search = api.buildTools(win, CONFIG, api.createClient(win, CONFIG))[0];
    const out = JSON.parse(await search.execute({ query: 'hoodie' }));
    assert.deepEqual(out.products[1].options, [{ code: 'size', label: 'Size', values: ['S', 'M'] }]);
});

test('get_product strips HTML and reports not found', async () => {
    let call = 0;
    const { win } = fakeWindow({
        responses: {
            [CONFIG.urls.graphql]: () => (call++ === 0
                ? { data: { products: { items: [{ ...SEARCH_RESPONSE.data.products.items[0],
                    short_description: { html: '<p>Light &amp; <b>strong</b></p>' },
                    description: { html: '<script>alert(1)</script><p>Great bag</p>' } }] } } }
                : { data: { products: { items: [] } } })
        }
    });
    const tool = api.buildTools(win, CONFIG, api.createClient(win, CONFIG)).find((t) => t.name === 'get_product');
    const found = JSON.parse(await tool.execute({ sku: '24-MB02' }));
    assert.equal(found.product.short_description, 'Light & strong');
    assert.equal(found.product.description, 'Great bag');
    const missing = JSON.parse(await tool.execute({ sku: 'NOPE' }));
    assert.equal(missing.error, 'not_found');
});

test('GraphQL errors reject the tool call', async () => {
    const { win } = fakeWindow({ responses: { [CONFIG.urls.graphql]: { errors: [{ message: 'boom' }] } } });
    const search = api.buildTools(win, CONFIG, api.createClient(win, CONFIG))[0];
    await assert.rejects(search.execute({ query: 'x' }), /boom/);
});

test('add_to_cart posts form key and options, reloads Luma mini cart', async () => {
    const required = [];
    const { win, calls } = fakeWindow({ responses: { [CONFIG.urls.cartAdd]: { ok: true, cart: { items_qty: 1 } } } });
    win.require = (deps, cb) => { required.push(deps[0]); cb({ reload: (s) => required.push(s.join()) }); };
    const tool = api.buildTools(win, CONFIG, api.createClient(win, CONFIG)).find((t) => t.name === 'add_to_cart');
    const out = JSON.parse(await tool.execute({ sku: 'MH01', qty: 2, options: { size: 'M' } }));
    assert.equal(out.ok, true);
    const body = new URLSearchParams(calls[0].init.body);
    assert.equal(body.get('form_key'), 'KEY123');
    assert.equal(body.get('qty'), '2');
    assert.deepEqual(JSON.parse(body.get('options')), { size: 'M' });
    assert.deepEqual(required, ['Magento_Customer/js/customer-data', 'cart']);
    assert.equal(calls[0].init.headers['X-Angeo-Agent-Tool'], 'add_to_cart');
});

test('add_to_cart on Hyvä uses hyva.getFormKey and the reload event', async () => {
    const { win, calls, events } = fakeWindow({ hyva: true, responses: { [CONFIG.urls.cartAdd]: { ok: true, cart: {} } } });
    const tool = api.buildTools(win, CONFIG, api.createClient(win, CONFIG)).find((t) => t.name === 'add_to_cart');
    await tool.execute({ sku: '24-MB02' });
    assert.equal(new URLSearchParams(calls[0].init.body).get('form_key'), 'HYVAKEY');
    assert.deepEqual(events, ['reload-customer-section-data']);
});

test('add_to_cart passes server errors through and does not reload', async () => {
    const error = { ok: false, error: 'options_required', details: { missing: { size: ['S', 'M'] } } };
    const { win, events } = fakeWindow({ hyva: true, responses: { [CONFIG.urls.cartAdd]: error } });
    const tool = api.buildTools(win, CONFIG, api.createClient(win, CONFIG)).find((t) => t.name === 'add_to_cart');
    assert.deepEqual(JSON.parse(await tool.execute({ sku: 'MH01' })), error);
    assert.deepEqual(events, []);
});

test('go_to_checkout navigates to checkout', async () => {
    const { win } = fakeWindow();
    const tool = api.buildTools(win, CONFIG, api.createClient(win, CONFIG)).find((t) => t.name === 'go_to_checkout');
    const out = JSON.parse(await tool.execute({}));
    assert.equal(out.ok, true);
    assert.equal(win.location.assigned, CONFIG.urls.checkout);
});

test('unregister aborts the shared signal', async () => {
    const { win, registered } = fakeWindow();
    await api.boot(win);
    win.angeoWebMcp.unregister();
    assert.equal(registered[0].options.signal.aborted, true);
});
