#!/bin/bash
# End-to-end tests for the evvnt-calendar plugin against a local WordPress install.
#
# Usage: tests/run-integration.sh <wp-root> <admin-creds-file>
#
#   <wp-root>           A WordPress install with an EMPTY database, served at
#                       http://localhost:9400 (e.g. `php -S localhost:9400 -t <wp-root>`),
#                       WP_DEBUG_LOG writing to <wp-root>/debug.log, and this plugin
#                       linked into wp-content/plugins/evvnt-calendar.
#   <admin-creds-file>  One line: "<username> <password>" for the admin account the
#                       script creates. Use throwaway test values.
#
# Environment: PHP (default: php on PATH), EVVNT_TEST_PUBLISHER (default 5293).
# The server-mode section calls the live Evvnt discovery API for that publisher.
set -u
W="$1"; CREDS="$2"
B=http://localhost:9400
PHP="${PHP:-php}"
PUBLISHER="${EVVNT_TEST_PUBLISHER:-5293}"
J=$(mktemp); PASS=0; FAIL=0
PAGE="$B/wp-admin/options-general.php?page=widget-for-evvnt-calendar-settings"
OPT=widget-for-evvnt-calendar-settings
read U P < "$CREDS"

ok()   { PASS=$((PASS+1)); echo "  PASS $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL $1 :: $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected [$3] got [$2]"; fi; }
wpphp(){ (cd "$W" && "$PHP" -r "require 'wp-load.php'; $1" 2>/dev/null); }
py()   { python3 -I -c "$1"; }

post_settings() {
  local N; N=$(curl -s -c $J -b $J "$PAGE" | grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -L -c $J -b $J "$B/wp-admin/options.php" \
    -d "option_page=widget-for-evvnt-calendar&action=update&_wpnonce=$N&_wp_http_referer=/wp-admin/options-general.php?page=$OPT" "$@"
}
notices() { py "
import sys,re;h=sys.stdin.read()
print('|'.join(re.sub(r'<[^>]+>|\s+',' ',m.group(1)).strip() for m in re.finditer(r'<div[^>]*class=[\"\x27][^\"\x27]*settings-error[^\"\x27]*[\"\x27][^>]*>(.*?)</div>',h,re.S)))"; }
embed_summary() { py "
import sys,re;h=sys.stdin.read()
divs=re.findall(r'<div id=\"(evvnt-calendar-\d+)\" class=\"evvnt-calendar\">',h)
els=re.findall(r'\"element\":\"#(evvnt-calendar-\d+)\"',h)
loaders=len(re.findall(r'<script[^>]*src=\"[^\"]*evvnt_discovery_plugin-latest\.min\.js',h))
live=[m for m in re.findall(r'.{0,12}alert\(',h) if 'u003E' not in m]
cats=re.findall(r'\"category_id\":(\d+)',h)
print(len(divs), loaders, len(els), int(sorted(divs)==sorted(els)), len(live), ','.join(cats))"; }

echo "== setup"
: > "$W/debug.log"
curl -s -o /dev/null -X POST "$B/wp-admin/install.php?step=2" --data-urlencode "weblog_title=Evvnt Test" \
  --data-urlencode "user_name=$U" --data-urlencode "admin_password=$P" --data-urlencode "admin_password2=$P" \
  --data-urlencode "pw_weak=1" --data-urlencode "admin_email=admin@example.test" -d "blog_public=0&language="
check "WordPress installed" "$(wpphp 'echo is_blog_installed()?1:0;')" 1
wpphp 'require_once ABSPATH."wp-admin/includes/plugin.php"; activate_plugin("evvnt-calendar/evvnt-calendar.php"); switch_theme("twentytwentyone");'
check "plugin active" "$(wpphp 'echo is_plugin_active("evvnt-calendar/evvnt-calendar.php")?1:0;')" 1
curl -s -c $J -b $J -o /dev/null "$B/wp-login.php"
curl -s -c $J -b $J -o /dev/null --data-urlencode "log=$U" --data-urlencode "pwd=$P" -d "wp-submit=Log+In&testcookie=1" "$B/wp-login.php"

echo "== settings page"
check "fresh settings page renders" "$(curl -s -c $J -b $J -o /dev/null -w '%{http_code}' "$PAGE")" 200
check "settings page lists 7 fields" "$(curl -s -c $J -b $J "$PAGE" | grep -c "name=\"$OPT\[")" 7
R=$(post_settings --data-urlencode "$OPT[api_key]=fake" --data-urlencode "$OPT[api_secret_key]=fake" --data-urlencode "$OPT[publisher_id]=12" | notices)
check "first save validates creds (one error)" "$R" "Settings saved, but Evvnt could not authenticate these credentials. Please check the API Key, API Secret Key and Publisher ID."
post_settings --data-urlencode "$OPT[api_key]=key\"><script>alert(1)</script>" --data-urlencode "$OPT[api_secret_key]=ab%2fCD<x9" \
  --data-urlencode "$OPT[publisher_id]=12abc" --data-urlencode "$OPT[partner_name]=Acme\" onfocus=\"alert(1)" \
  --data-urlencode "$OPT[submission_label]=It's <b>live</b> \\ ok" >/dev/null
check "secret stored exactly" "$(wpphp "echo get_option('$OPT')['api_secret_key'];")" 'ab%2fCD<x9'
check "publisher id coerced to int" "$(wpphp "echo get_option('$OPT')['publisher_id'];")" 12
check "label: tags stripped, backslash kept" "$(wpphp "echo get_option('$OPT')['submission_label'];")" "It's live \\ ok"
H=$(curl -s -c $J -b $J "$PAGE")
check "api_key escaped in form" "$(echo "$H" | grep -c 'value="key&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"')" 1
check "partner_name escaped in form" "$(echo "$H" | grep -c 'value="Acme&quot; onfocus=&quot;alert(1)"')" 1
check "secret never in page" "$(echo "$H" | grep -c 'ab%2fCD')" 0
R=$(post_settings --data-urlencode "$OPT[api_key]=K2" --data-urlencode "$OPT[api_secret_key]=" --data-urlencode "$OPT[publisher_id]=abc" | notices)
check "missing publisher -> required error" "$R" "API Key, API Secret Key and a numeric Publisher ID are all required."
check "blank secret keeps saved secret" "$(wpphp "echo get_option('$OPT')['api_secret_key'];")" 'ab%2fCD<x9'

echo "== front end"
IDS=$(wpphp '
update_option("widget-for-evvnt-calendar-settings", array("api_key"=>"fake","api_secret_key"=>"fake","publisher_id"=>"12","partner_name"=>"Acme \"</script><script>alert(9)</script>","submission_label"=>"Go"));
$c = wp_insert_user(array("user_login"=>"contrib","user_pass"=>wp_generate_password(),"role"=>"contributor"));
$p1 = wp_insert_post(array("post_type"=>"page","post_status"=>"publish","post_title"=>"Two calendars","post_content"=>"[evvnt]\n\n[evvnt config_type=\"widget\" map=\"1\" category_id=\"7\"]"));
$p2 = wp_insert_post(array("post_type"=>"post","post_status"=>"pending","post_author"=>$c,"post_title"=>"Contributor payload","post_content"=>"[evvnt category_id=\"1}});} alert(document.domain); function z(){ z({a:{b:1\"] [evvnt this=\"x\"]"));
update_option("widget_evvnt_calendar_widget", array(2=>array("config_type"=>"calendar","detail_page_enabled"=>"","virtual"=>"0","map"=>"0","seo_optimize"=>"1","category_id"=>"5"), "_multiwidget"=>1));
$sw = get_option("sidebars_widgets"); $sw["sidebar-1"] = array("evvnt_calendar_widget-2"); update_option("sidebars_widgets",$sw);
echo "$p1 $p2";')
read PAGE_ID POST_ID <<< "$IDS"
check "classic theme: divs loaders inits match live-alerts cats" "$(curl -s "$B/?page_id=$PAGE_ID" | embed_summary)" "3 1 3 1 0 7,5"
check "contributor payload neutralised (admin preview)" "$(curl -s -c $J -b $J "$B/?p=$POST_ID&preview=true" | embed_summary)" "3 1 3 1 0 1,5"
check "legacy widget keeps detail_page_enabled=false" "$(curl -s "$B/?page_id=$PAGE_ID" | grep -c '"element":"#evvnt-calendar-[0-9]*","detail_page_enabled":false')" 1
wpphp 'switch_theme("twentytwentyfive");'
check "block theme (TT5): divs loaders inits match live-alerts cats" "$(curl -s "$B/?page_id=$PAGE_ID" | embed_summary)" "2 1 2 1 0 7"
wpphp 'switch_theme("twentytwentyone");'

echo "== widget form/update"
R=$(wpphp '
$w = new Evvnt_Calendar_Widget(); $w->_set(2); $legacy = get_option("widget_evvnt_calendar_widget")[2];
ob_start(); $w->form($legacy); $f = ob_get_clean(); preg_match_all("/<option value=\"([^\"]*)\"\s*selected/", $f, $m);
$a = json_encode($m[1]);
$b = json_encode($w->update(array("config_type"=>"widget","detail_page_enabled"=>"0","map"=>"1"), $legacy));
$c = json_encode($w->update(array("category_id"=>"9}});alert(1)//","config_type"=>"evil","detail_page_enabled"=>"x"), $legacy));
set_transient("evvnt_calendar_categories_" . md5("12|fake|fake"), array(array("id"=>5,"name"=>"Music <b>&</b>"),array("id"=>6,"name"=>"Arts")), 60);
ob_start(); $w->form($legacy); $f = ob_get_clean();
$d = (int) (strpos($f, "<option value=\"5\"  selected=\x27selected\x27>Music &lt;b&gt;&amp;&lt;/b&gt;</option>") !== false);
echo "$a\n$b\n$c\n$d";')
check "form shows legacy values as rendered" "$(sed -n 1p <<< "$R")" '["0","calendar"]'
check "update keeps category when field absent" "$(sed -n 2p <<< "$R")" '{"config_type":"widget","detail_page_enabled":"0","virtual":"0","map":"1","category_id":"5"}'
check "update coerces hostile input" "$(sed -n 3p <<< "$R")" '{"config_type":"calendar","detail_page_enabled":"1","virtual":"0","map":"0","category_id":"9"}'
check "category select: selected + escaped" "$(sed -n 4p <<< "$R")" 1

echo "== server rendering (live API, publisher $PUBLISHER)"
wpphp "update_option('$OPT', array('publisher_id'=>'$PUBLISHER','render_mode'=>'server','submission_label'=>'Promote your event')); update_option('permalink_structure','/%postname%/'); flush_rewrite_rules(false); wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_name'=>'events','post_title'=>'Events','post_content'=>'[evvnt]'));"
E="$B/events/"
status() { curl -s -o /dev/null -w '%{http_code}' "$1"; }
redirect() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$1"; }
LIST=$(curl -s "$E")
check "calendar renders server-side" "$(echo "$LIST" | grep -c 'class="evvnt-cal evvnt-cal--calendar"')" 1
check "only the sidebar widget is a JS embed" "$(echo "$LIST" | grep -o 'class="evvnt-calendar"' | wc -l | tr -d ' ')" 1
check "calendar lists events" "$(echo "$LIST" | grep -o 'class="evvnt-cal__card"' | wc -l | tr -d ' ')" 30
check "calendar links to page 2" "$(echo "$LIST" | grep -c 'rel="next" href="http://localhost:9400/events/?evPage=2"')" 1
DETAIL_PATH=$(echo "$LIST" | grep -o '_evDiscoveryPath=/event/[A-Za-z0-9-]*' | head -1)
check "event links use the discovery URL scheme" "$([ -n "$DETAIL_PATH" ] && echo yes)" yes
D=$(curl -s "$E?$DETAIL_PATH")
check "event detail 200" "$(status "$E?$DETAIL_PATH")" 200
check "event detail renders" "$(echo "$D" | grep -c 'evvnt-cal--detail')" 1
check "exactly one canonical, pointing at the event" "$(echo "$D" | grep -o '<link rel="canonical" href="[^"]*"' )" "<link rel=\"canonical\" href=\"$E?$DETAIL_PATH\""
check "Event JSON-LD present" "$(echo "$D" | grep -c '"@type":"Event"')" 1
check "og:title present" "$(echo "$D" | grep -c '<meta property="og:title"')" 1
ID=$(echo "$DETAIL_PATH" | sed 's#.*/event/\([A-Za-z0-9]*\)-.*#\1#')
check "wrong slug -> 301 to canonical" "$(redirect "$E?_evDiscoveryPath=/event/$ID-wrong-slug")" "301 $E?$DETAIL_PATH"
check "legacy _ev_id -> 301" "$(redirect "$E?_ev_id=$ID")" "301 $E?_evDiscoveryPath=/event/$ID"
check "missing event -> 404" "$(status "$E?_evDiscoveryPath=/event/999999999-nope")" 404
check "missing event is noindex" "$(curl -s "$E?_evDiscoveryPath=/event/999999999-nope" | grep -c "name='robots' content='noindex")" 1
check "unknown category -> 404" "$(status "$E?_evDiscoveryPath=/no-such-category")" 404
check "malformed path -> 404" "$(status "$E?_evDiscoveryPath=/a/b/c")" 404
CAT=$(echo "$LIST" | grep -o 'href="http://localhost:9400/events/?_evDiscoveryPath=/[a-z0-9-]*"' | head -1 | sed 's/href="//;s/"$//')
check "category page 200" "$(status "$CAT")" 200
check "category page canonical is itself" "$(curl -s "$CAT" | grep -o '<link rel="canonical" href="[^"]*"')" "<link rel=\"canonical\" href=\"$CAT\""
check "page 2 200" "$(status "$E?evPage=2")" 200
check "page 2 links back" "$(curl -s "$E?evPage=2" | grep -c 'rel="prev" href="http://localhost:9400/events/"')" 1
echo "== path URLs (live API, publisher $PUBLISHER)"
CAL_ID=$(wpphp "echo get_page_by_path('events')->ID;")
wpphp "update_option('$OPT', array_merge(get_option('$OPT'), array('calendar_page_id'=>$CAL_ID)));"
curl -s -o /dev/null "$B/"   # first request after the settings change registers and flushes the rules
P=$(curl -s "$B/events/")
PEV=$(echo "$P" | grep -o 'href="http://localhost:9400/event/[A-Za-z0-9-]*/"' | head -1 | sed 's/href="//;s/"$//')
check "calendar links are paths" "$([ -n "$PEV" ] && echo yes)" yes
check "event path 200" "$(status "$PEV")" 200
check "event path canonical is itself" "$(curl -s "$PEV" | grep -o '<link rel="canonical" href="[^"]*"')" "<link rel=\"canonical\" href=\"$PEV\""
PSEG=$(echo "$PEV" | sed 's#.*/event/\([^/]*\)/#\1#'); PID=$(echo "$PSEG" | sed 's/-.*//')
check "query-string event link -> 301 path (keeps utm)" "$(redirect "$B/events/?_evDiscoveryPath=/event/$PSEG&utm_source=x")" "301 $PEV?utm_source=x"
check "query-string link with old slug -> one 301 to canonical" "$(redirect "$B/?_evDiscoveryPath=/event/$PID-old-slug")" "301 $PEV"
check "legacy _ev_id -> one 301 to canonical" "$(redirect "$B/?_ev_id=$PID")" "301 $PEV"
check "wrong slug on path -> 301" "$(redirect "$B/event/$PID-old-slug/")" "301 $PEV"
check "missing trailing slash -> 301" "$(redirect "${PEV%/}")" "301 $PEV"
check "query-string category + page -> 301 path" "$(redirect "$B/events/?_evDiscoveryPath=/nightlife&evPage=3")" "301 $B/events/category/nightlife/page/3/"
check "evPage on the calendar page -> 301 path" "$(redirect "$B/events/?evPage=2")" "301 $B/events/page/2/"
check "category path 200" "$(status "$B/events/category/nightlife/")" 200
check "page path 200" "$(status "$B/events/page/2/")" 200
check "missing event path -> 404" "$(status "$B/event/999999999-nope/")" 404
check "unknown category path -> 404" "$(status "$B/events/category/nope/")" 404
wpphp "update_option('$OPT', array_merge(get_option('$OPT'), array('calendar_page_id'=>0)));"
curl -s -o /dev/null "$B/"
check "without a calendar page, query-string URLs still work" "$(status "$E?$DETAIL_PATH")" 200

wpphp "update_option('$OPT', array_merge(get_option('$OPT'), array('render_mode'=>'js'))); update_option('permalink_structure',''); flush_rewrite_rules(false);"

echo "== unconfigured"
wpphp 'update_option("widget-for-evvnt-calendar-settings", array());'
check "anon sees nothing" "$(curl -s "$B/?page_id=$PAGE_ID" | grep -c 'evvnt-calendar-unconfigured\|evvnt_discovery_plugin')" 0
check "admin sees notice (2 shortcodes + widget)" "$(curl -s -c $J -b $J "$B/?page_id=$PAGE_ID" | grep -o 'class="evvnt-calendar-unconfigured"' | wc -l | tr -d ' ')" 3

echo "== uninstall"
wpphp 'update_option("widget-for-evvnt-calendar-settings", array("publisher_id"=>"12")); set_transient("evvnt_calendar_categories_x", array(), 60);'
wpphp 'set_transient("evvnt_calendar_api_1_x", array("data"=>1,"at"=>1), 60); update_option("evvnt_calendar_cache_gen", 3); update_option("evvnt_calendar_rules_sig", "x");'
R=$(wpphp 'global $wpdb; define("WP_UNINSTALL_PLUGIN", "evvnt-calendar/evvnt-calendar.php"); include WP_PLUGIN_DIR . "/evvnt-calendar/uninstall.php";
echo json_encode($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"%evvnt%\""));')
check "uninstall removes all plugin options" "$R" "[]"
check "site still renders after uninstall" "$(curl -s -o /dev/null -w '%{http_code}' "$B/?page_id=$PAGE_ID")" 200

echo "== PHP log"
check "no plugin warnings/notices/deprecations" "$(grep -c 'plugins/evvnt-calendar\|RubymineProjects/evvnt' "$W/debug.log")" 0
NON_DEP=$(grep -v 'Deprecated' "$W/debug.log" | grep -c .)
check "no non-deprecation log lines at all" "$NON_DEP" 0
echo "   (core deprecation lines: $(grep -c Deprecated "$W/debug.log"))"

rm -f $J
echo "== RESULT: $PASS passed, $FAIL failed"
