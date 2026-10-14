# Changelog

All notable changes to `angeo/module-webmcp` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/) and the
project adheres to [Semantic Versioning](https://semver.org/).

---

## [1.0.0] - 2026-10-14

First release.

### Added

- Storefront script that registers five WebMCP tools with
  `document.modelContext.registerTool()` (fallback: `navigator.modelContext`):
  `search_products`, `get_product`, `add_to_cart`, `get_cart`, `go_to_checkout`.
- `POST /webmcp/cart/add` and `GET /webmcp/cart/view` for the cart tools,
  using the shopper's session cart. Form key checked by Magento's CSRF
  validator; product status, visibility, website and quantity checked in PHP.
- Configurable products: options by attribute code or label, case-insensitive;
  missing or wrong options return the allowed values.
- Admin settings: enable, Chrome origin trial token, per-tool switches,
  max search results, max quantity.
- Luma and Hyvä support from one script (mini cart refresh for both).
- Node tests for the script, PHPUnit tests for option matching and config.
