<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
delete_option('wmr_settings');
delete_site_transient('wmr_release');
foreach (['_wmr_ids', '_wmr_time', '_wmr_off'] as $k) delete_post_meta_by_key($k);
