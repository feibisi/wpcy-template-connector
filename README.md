# WPCY Template Connector

Connect Elementor's remote template library to `https://templates.wpcy.net`.

## What it intercepts

- `my.elementor.com/api/connect/v1/library/templates`
- `my.elementor.com/api/connect/v1/library/get_template_content`
- `my.elementor.com/api/v1/templates/info/`
- Elementor Cloud Library resource endpoints

## Current behavior

Version `1.0.2` prefers the native-compatible Worker endpoints:

- `GET /api/connect/v1/library/templates` returns the Elementor remote template JSON array.
- `GET /api/v1/templates/info/` returns `templates`, `categories`, `types_data`, and `config`.
- `POST /api/connect/v1/library/get_template_content` returns `{content, page_settings}`.

Legacy `/api/templates` and `/api/template/{id}` are still kept as fallback paths.

## Settings page

Open **Elementor → WPCY Templates** in wp-admin to view connector status, run endpoint diagnostics, and clear Elementor remote template cache.

## Install

Copy this directory to:

```text
wp-content/plugins/wpcy-template-connector/
```

Then activate **WPCY Template Connector** in WordPress.

## Verify

```bash
php -l wpcy-template-connector.php
```

In Elementor, open the Template Library and import a remote template.
