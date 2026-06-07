# WPCY Template Connector

**Author:** WPCY.COM

**Plugin URI:** <https://wpcy.com>

**Template API:** <https://templates.wpcy.net>

**Update API:** <https://updates.wenpai.net>

Connect Elementor's remote template library to the WPCY template repository.

## Plugin metadata

- Plugin name: WPCY Template Connector
- Author: WPCY.COM
- Author URI: <https://wpcy.com>
- Requires WordPress: 6.0+
- Tested up to: 6.8
- Requires PHP: 7.4+
- Requires plugin: Elementor
- License: GPL-2.0-or-later
- Text domain: `wpcy-template-connector`

## What it intercepts

- `my.elementor.com/api/connect/v1/library/templates`
- `my.elementor.com/api/connect/v1/library/get_template_content`
- `my.elementor.com/api/v1/templates/info/`
- Elementor Cloud Library resource endpoints

## Current behavior

Version `1.0.4` prefers the native-compatible Worker endpoints:

- `GET /api/connect/v1/library/templates` returns the Elementor remote template JSON array.
- `GET /api/v1/templates/info/` returns `templates`, `categories`, `types_data`, and `config`.
- `POST /api/connect/v1/library/get_template_content` returns `{content, page_settings}`.

Legacy `/api/templates` and `/api/template/{id}` are still kept as fallback paths.

## Updates

The plugin declares `Update URI: https://updates.wenpai.net` and bundles WenPai updater support so WordPress can receive package updates from the WenPai/FeiCode update channel.

## Settings page

Open **Elementor → Connector** in wp-admin to view connector status, run endpoint diagnostics, and clear Elementor remote template cache.

## Install

Copy this directory to:

```text
wp-content/plugins/wpcy-template-connector/
```

Then activate **WPCY Template Connector** in WordPress.

## Verify

```bash
php -l wpcy-template-connector.php
php -l includes/class-wenpai-updater.php
```

In Elementor, open the Template Library and import a remote template.
