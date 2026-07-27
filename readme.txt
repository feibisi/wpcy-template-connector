=== WPCY Template Connector ===
Contributors: wpcy
Tags: elementor, templates, template-library, connector
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Requires Plugins: elementor
Stable tag: 1.0.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects Elementor's remote template library to templates.wpcy.net and routes template imports through the WPCY template repository.

== Description ==

WPCY Template Connector lets Elementor load remote templates from the WPCY template repository at templates.wpcy.net instead of my.elementor.com.

The plugin intercepts Elementor template-library HTTP requests and forwards them to native-compatible WPCY endpoints when available:

* Source_Remote template list: api/connect/v1/library/templates
* Library template import: api/connect/v1/library/get_template_content
* Template metadata: api/v1/templates/info
* Elementor Cloud Library resource endpoints

It also provides an Elementor admin submenu named Connector for endpoint diagnostics, connector status, and Elementor remote template cache clearing.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/wpcy-template-connector/
2. Activate WPCY Template Connector from the Plugins screen.
3. Open Elementor's template library and import remote templates as usual.
4. Optional: open Elementor → Connector to run diagnostics or clear the Elementor remote template cache.

== Frequently Asked Questions ==

= Who maintains this plugin? =

The plugin is maintained by WPCY.COM.

= Which template API does it use? =

It uses https://templates.wpcy.net for template metadata and template content, with legacy fallback endpoints retained for compatibility.

= How are plugin updates delivered? =

The plugin declares Update URI: https://updates.wenpai.net and bundles the WenPai updater so WordPress can receive packages from the FeiCode/WenPai update channel.

== Changelog ==

= 1.0.5 =
* Fix a fatal error ("Cannot declare class WenPai_Updater, because the name is already in use") when this plugin is installed alongside other WenPai plugins such as WPBridge.
* Rename the bundled updater class to WPCY_Template_Connector_Updater so it no longer occupies a shared global class name.

= 1.0.4 =
* Complete plugin metadata: author, author URI, WordPress/PHP requirements, license, tested version, and readme details.
* Set author to WPCY.COM.

= 1.0.3 =
* Add WenPai updater integration via Update URI https://updates.wenpai.net.
* Rename Elementor submenu to Connector.

= 1.0.2 =
* Add Elementor submenu settings page with endpoint diagnostics and cache clearing.

= 1.0.1 =
* Use templates.wpcy.net native Elementor-compatible endpoints for template list, info, and content.
* Keep legacy /api/templates fallback for compatibility.

= 1.0.0 =
* Initial connector.
