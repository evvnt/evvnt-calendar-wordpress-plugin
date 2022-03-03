<?php
/**
 * Provide a public-facing view for the plugin
 *
 * This file is used to markup the public-facing aspects of the plugin.
 *
 * @link       http://evvnt.com
 * @since      1.0.0
 *
 * @package    Evvnt_Calendar
 * @subpackage Evvnt_Calendar/public/partials
 */
?>
<?php
  $cal_options = get_option('widget-for-evvnt-calendar-settings');
  if (isset($cal_options)) { ?>
<div id="evvnt-calendar"></div>
<script async defer src="<?php echo EVVNT_CALENDAR_SCRIPT_URL ?>" onload="evvntDiscoveryInit();"></script>
<script>
  function evvntDiscoveryInit() {
    evvnt_require("evvnt/discovery_plugin").init({
      api_key: "<?php echo $cal_options['api_key'] ?>",
      publisher_id: <?php echo $cal_options['publisher_id'] ?>,
      discovery: {
        element: "#evvnt-calendar",
        detail_page_enabled: <?php echo ($detail_page_enabled == 1 || $detail_page_enabled == 'true') ? 'true' : 'false' ?>,
        widget: <?php echo $config_type == 'widget' ? 'true' : 'false' ?>,
        virtual: <?php echo ($virtual == 1 || $virtual == 'true') ? 'true' : 'false' ?>,
        map: <?php echo ($map == 1 || $map == 'true') ? 'true' : 'false' ?>,
        seo_optimize: <?php echo ($seo_optimize == 1 || $seo_optimize == 'true') ? 'true' : 'false' ?>,
       <?php if ($category_id != '') {  echo 'category_id: '.$category_id; } ?>
      }
   });
 }
</script>
<?php } else { ?>
  <span>Evvnt Calendar is not configured</span>
<?php }
?>
