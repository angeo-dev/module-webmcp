# Angeo WebMCP for Magento 2

[![CI](https://github.com/angeo-dev/module-webmcp/actions/workflows/ci.yml/badge.svg)](https://github.com/angeo-dev/module-webmcp/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-webmcp)](https://packagist.org/packages/angeo/module-webmcp)
[![Downloads](https://img.shields.io/packagist/dt/angeo/module-webmcp)](https://packagist.org/packages/angeo/module-webmcp)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%E2%80%93%208.5-777bb4)](composer.json)
[![Magento](https://img.shields.io/badge/Magento-2.4.6%20%E2%80%93%202.4.9-f26322)](composer.json)
[![Hyvä](https://img.shields.io/badge/Hyv%C3%A4-compatible-0a7)](#luma-and-hyvä)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**Let AI agents in the shopper's browser use your store through real tools — not by clicking around.**

[WebMCP](https://developer.chrome.com/docs/ai/webmcp) is a new browser standard
from Google and Microsoft. A web page registers named tools, and an AI agent
running in the browser calls them. This module registers five store tools on
every storefront page.

| Tool | What it does | Changes data? |
|---|---|---|
| `search_products` | Search the catalog: SKU, name, live price, stock, URL, variant options | No |
| `get_product` | Full details of one product by SKU | No |
| `add_to_cart` | Add a product to the **shopper's own** cart, with variant options | Cart only |
| `get_cart` | Items and totals in the shopper's cart | No |
| `go_to_checkout` | Open the checkout page | No |

The agent **never pays and never places an order**. The shopper finishes
checkout as usual.

## Status of WebMCP (October 2026)

* Chrome only, as a public **origin trial** (Chrome 149 – 156).
* Without an origin trial token, tools work only with the Chrome flag
  *"WebMCP for testing"* (good for development).
* Other browsers: not yet. In browsers without WebMCP the script exits at once
  and does nothing.

The API may still change during the trial. This module uses
`document.modelContext.registerTool(tool, { signal })`, the trial shape since
Chrome 150, and falls back to the older `navigator.modelContext`.

## Requirements

* Magento Open Source, Mage-OS or Adobe Commerce **2.4.6+** (PHP 8.1 – 8.5)
* GraphQL enabled (it is by default) — the read tools use the store's own `/graphql`
* Luma or Hyvä

## Install

```bash
composer require angeo/module-webmcp
bin/magento module:enable Angeo_WebMcp
bin/magento setup:upgrade
bin/magento cache:flush
```

In production mode, also run `bin/magento setup:static-content:deploy`.

## Configure

**Stores → Configuration → Angeo → WebMCP**

* **Enable WebMCP Tools** — on by default.
* **Chrome Origin Trial Token** — register your domain at
  [Chrome Origin Trials](https://developer.chrome.com/origintrials/) (trial
  name: WebMCP) and paste the token. It is printed as
  `<meta http-equiv="origin-trial">` in the page head. A token is tied to one
  origin, so set it per store view if your store views use different domains.
* **Tools** — switch each tool on or off.
* **Limits** — max search results (default 10, hard cap 50) and max quantity
  per add to cart (default 100).

## Try it

1. Open Chrome 149+, go to `chrome://flags`, search for **WebMCP** and turn
   on *WebMCP for testing* (or set an origin trial token).
2. Open any page of your store.
3. In DevTools, open the **WebMCP** panel: you see the five tools.
4. Or run in the console:

```js
angeoWebMcp.tools
// ['search_products', 'get_product', 'add_to_cart', 'get_cart', 'go_to_checkout']

await document.modelContext.executeTool(
  (await document.modelContext.getTools()).find(t => t.name === 'search_products'),
  { query: 'backpack' }
)
```

## How it works

```
Browser tab (shopper + agent)                 Magento
──────────────────────────────                ─────────────────────────────
webmcp.js registers tools  ──search/get──▶   POST /graphql   (public catalog)
                           ──add_to_cart──▶  POST /webmcp/cart/add  (form key)
                           ──get_cart─────▶  GET  /webmcp/cart/view (no-store)
                           ──checkout─────▶  navigates to /checkout
```

* **Read tools** call the store's own GraphQL with the current store code. They
  see exactly what the shopper sees.
* **Cart tools** use the shopper's session cart. `add_to_cart` is a frontend
  POST, so Magento checks the **form key** first — the same CSRF protection as
  the normal add-to-cart button.
* **Server checks** (in PHP, not in the prompt): product must be enabled,
  visible and assigned to the current website; quantity is capped; stock and
  sale rules run through `Quote::addProduct()`. Hidden products answer
  "not found".
* Supported product types for `add_to_cart`: simple, virtual, configurable.
  For others, and for products with required custom options, the tool returns
  the product URL so the agent can send the shopper there.
* When a configurable option is missing or wrong, the answer lists the allowed
  values, so the agent can fix the call itself.
* Product names and descriptions are marked with `untrustedContentHint`:
  catalog text is data for the agent, not instructions.

## Luma and Hyvä

One plain script, no RequireJS, no Alpine, no build step. The config is printed
as `<script type="application/json">`, so no inline JavaScript is needed and
strict CSP stays happy.

After `add_to_cart` the mini cart refreshes:

* **Hyvä:** dispatches `reload-customer-section-data`, form key from `hyva.getFormKey()`
* **Luma:** reloads the `cart` section through `Magento_Customer/js/customer-data`

## Full-page cache

Storefront pages stay cacheable: the printed config is the same for every
shopper. `/webmcp/cart/*` responses send `Cache-Control: no-store`. If your
Varnish or LiteSpeed rules cache unknown paths, exclude `/webmcp/`.

## Works well with

* [`angeo/module-mcp-server`](https://github.com/angeo-dev/module-mcp-server) —
  server-side MCP for agents that connect directly (not in a browser).
* [`angeo/module-ucp`](https://github.com/angeo-dev/module-ucp) — UCP profile
  for Google AI Mode and Gemini.
* Or install everything with
  [`angeo/agentic-commerce-suite`](https://github.com/angeo-dev/agentic-commerce-suite).

## Development

```bash
node --test Test/js/webmcp.test.js     # storefront script
vendor/bin/phpunit                     # PHP unit tests
```

## License

MIT. Made by [angeo.dev](https://angeo.dev).
