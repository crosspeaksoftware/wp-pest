<?php

/** Plugin Name: WP Pest Integration Fixture */
define('WP_PEST_TEST_ADMIN_AT_PLUGIN_LOAD', is_admin());

define('WP_PEST_SHARED_AT_PLUGIN_LOAD', defined('WP_PEST_TEST_SHARED') ? WP_PEST_TEST_SHARED : null);
