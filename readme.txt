=== WPCY Template Connector ===
Contributors: wpcy
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Requires Plugins: elementor
Stable tag: 1.0.2
License: GPL-2.0+

Connects Elementor template library to templates.wpcy.net, replacing my.elementor.com API calls.

== Description ==

Routes all Elementor template library HTTP requests to the WPCY template repository (templates.wpcy.net).

Intercepts three template API surfaces and forwards them to native-compatible WPCY endpoints when available:
- Source_Remote (api/connect/v1/library/templates)
- Library app (api/connect/v1/library/get_template_content)
- Cloud Library (cloud-library.prod.builder.elementor.red)

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate from Plugins screen
3. Elementor template library now serves from templates.wpcy.net

Diagnostics and cache controls are available from Elementor → WPCY Templates.

== Changelog ==

= 1.0.2 =
* Add Elementor submenu settings page with endpoint diagnostics and cache clearing.

= 1.0.1 =
* Use templates.wpcy.net native Elementor-compatible endpoints for template list, info, and content.
* Keep legacy /api/templates fallback for compatibility.

= 1.0.0 =
* Initial connector.
