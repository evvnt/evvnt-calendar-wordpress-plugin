# Evvnt Calendar Wordpress Plugin

A Wordpress Plugin that provides Widget and Shortcode functions for displaying the Evvnt Discovery Plugin.

## Description

This is a simple plugin that provides an admin page to set your Evvnt Calendar credentials and settings, then allows
provides a Widget or Shortcode for displaying it on a page.

## Installation

1. Upload the plugin folder to your /wp-content/plugins/ folder.
1. Go to the **Plugins** page and activate the plugin.
1. Get your credentials from the Evvnt Admin and copy to the settings page of the plugin.
1. Add the `[evvnt]` shortcode to a page, or add the Evvnt Calendar Widget to a sidebar. Choose a category in the widget settings or with `[evvnt category_id="123"]`.

## Rendering modes

**Settings → Evvnt Calendar Settings → Rendering** chooses how the `[evvnt]` shortcode is shown:

- **In the browser (default):** the page loads Evvnt's discovery app, which fetches and draws the calendar with JavaScript.
- **On the server (beta):** the calendar and event pages are built in PHP from Evvnt's public discovery API, so search engines and visitors without JavaScript see real content.
  - **What gets rendered:**
    - category tabs, featured events and the event list, with pagination
    - event detail pages
    - title, canonical, description, Open Graph and Event JSON-LD tags
  - **URLs:** with pretty permalinks and a **Calendar page** chosen in the settings, calendar URLs are paths:
    - events: `/event/{id}-{slug}/` (the `event` prefix can be changed with the `evvnt_calendar_event_base` filter)
    - categories: `/{calendar-page}/category/{slug}/`, plus `/page/{n}/` for further pages
    - Query-string links from the discovery app and Evvnt (`?_evDiscoveryPath=…`, `?_ev_id=…`, `?evPage=N`) get a single 301 to the path URL. Outdated slugs and missing trailing slashes are corrected too, and other query arguments such as `utm_*` are kept.
    - With no Calendar page, or with plain permalinks, the query-string URLs are used as they are.
    - A missing event returns 404. If Evvnt is unreachable and nothing is cached, the page returns 503.
  - **Caching:** API responses are cached in transients (10 minutes for lists, 1 hour for events, 12 hours for publisher settings). Saving the settings clears the cache.
  - **Calendar layout** (Settings → Evvnt Calendar Settings): *Featured + list* (featured events, then every event), or *Category rows*: a Featured row, a **This Weekend** row (Thursday to Sunday, or today to Sunday from Thursday on; **View more** opens `?fromDate=…&toDate=…`), then a horizontally scrolling row of each category's **featured** events, ending with **View more** (and **See all** in the row header) into that category. Categories with fewer than 5 featured events get no row (filter `evvnt_calendar_row_min_featured`). Rows scroll by swipe, trackpad or keyboard, with ‹ › buttons on larger screens. Category pages and later pages always use the list. A shortcode can override the setting with `[evvnt layout="rows"]` or `layout="list"`.
  - **Search:** a search bar sits at the top of the calendar. As you type (2+ characters), suggestions list matching categories and events; the results page (`?searchTerm=…`) shows matching categories, then events. Suggestions come from `/wp-json/evvnt-calendar/v1/suggest` (public, read-only, rate-limited to 60 requests a minute per visitor; filter `evvnt_calendar_suggest_rate_limit`). Results pages are `noindex`.
  - **Calendar width:** *Theme's wide width* (default) breaks out of the content column to the theme's wide size (`--wp--style--global--wide-size`, else 1200px). Choose *Content column* for pages with a sidebar.
  - **Not yet server-rendered:** the widget and `[evvnt config_type="widget"]` still use the discovery app.
  - **Templates:** copy any file from `public/templates/` to `{your-theme}/evvnt-calendar/` to override it.

## Tests

```bash
php tests/run-tests.php /path/to/wordpress            # unit tests, no network (uses tests/fixtures)
tests/run-integration.sh /path/to/wordpress creds.txt # end-to-end; see the header of the script
```
