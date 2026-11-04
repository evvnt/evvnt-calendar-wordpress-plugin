=== Evvnt Calendar Plugin ===
Contributors: evvnt
Tags: evvnt, events, calendar, ticketing
Requires at least: 6.0
Tested up to: 6.2
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A Wordpress Plugin for displaying the Evvnt Discovery Calendar.

== Description ==

This is a simple plugin that provides an admin page to set your Evvnt Calendar credentials and settings, then allows
provides a Widget or Shortcode for displaying it on a page.

= Rendering modes =

By default the calendar is drawn in the browser by Evvnt's discovery app. Under Settings → Evvnt Calendar Settings → Rendering you can switch the [evvnt] shortcode to server rendering (beta). The calendar and event pages are then built on your server, so search engines and visitors without JavaScript see the events, and event pages get proper titles, canonical URLs, social tags and Event structured data. Widgets still use the discovery app.

== Installation ==

1. Upload the plugin folder to your /wp-content/plugins/ folder.
1. Go to the **Plugins** page and activate the plugin.
1. Get your credentials from the Evvnt Admin and copy to the settings page of the plugin.
1. Add the `[evvnt]` shortcode to a page, or add the Evvnt Calendar Widget to a sidebar. Choose a category in the widget settings or with `[evvnt category_id="123"]`.

== Changelog ==
= 0.1.0 =
* New (beta): optional server-side rendering of the calendar and event pages, with category tabs, pagination, SEO head tags and Event JSON-LD. Existing event links keep working; legacy and outdated links redirect.
* With pretty permalinks and a Calendar page selected, event and category pages get clean URLs (/event/123-name/, /events/category/music/); older query-string links redirect to them.
* Calendar layout setting: featured + list, or category rows (horizontally scrolling rows of each category's featured events, with "View more"; categories with fewer than 5 featured events are left out).
* "This Weekend" row (Thursday to Sunday) in the category rows layout, with a date-range listing (?fromDate=&toDate=).
* Search bar with live suggestions matching categories and events, and a search results page.
* Event lists show 2–3 events per row on larger screens.
* Calendar width setting: the theme's wide width (default) or the content column.
* Event pages: full-width hero for featured events; standard layout like the discovery plugin for others; shared "About" and "Where It's Happening" panel with map, share buttons and clickable categories; recommended events.
* Themes can override the calendar templates in {theme}/evvnt-calendar/.
= 0.0.2 =
* Security: shortcode and widget options are now type-checked and JSON-encoded before reaching the page, fixing a script-injection issue in the [evvnt] shortcode.
* Security: settings are sanitized on save and escaped on output; the API secret is no longer displayed or sent to the browser, and the API key is no longer printed on public pages.
* Fix: multiple calendars on one page no longer conflict.
* Visitors without JavaScript now see a short message instead of an empty space; the block-based widget editor shows a live preview of the calendar.
* Fix: the widget now remembers the selected category, and caches the category list.
* Fix: "not configured" state is detected (shown to administrators only).
* Fix: removed requests for missing admin assets; removed the non-functional SEO checkbox.
* Settings and cached data are deleted when the plugin is uninstalled.
= 0.0.1 =
* Initial release
