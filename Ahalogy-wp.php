<?php
/*
Plugin Name: Ahalogy Sponsored Content
Plugin URI: https://app.ahalogy.com/
Description: Enables the automatic showcasing of your site’s content into the Ahalogy Sponsored Content Program.
Version: 2.1.0
Author: Ahalogy
Author URI: http://www.ahalogy.com
License: GPLv3
Copyright 2013-2014 Ahalogy (http://www.ahalogy.com)
*/

if ( ! function_exists( 'add_filter' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}

require_once dirname(__FILE__) . '/vendor/mixpanel-php/lib/Mixpanel.php';

if (!class_exists('ahalogyWP')): // namespace collision check
  class ahalogyWP
  {
    // declare globals
    public $options_name = 'ahalogy_wp_item';
    public $options_group = 'ahalogy_wp_option_option';
    public $options_page = 'ahalogy_wp';
    public $plugin_name = 'Ahalogy';
    public $plugin_textdomain = 'ahalogyWP';
    public $plugin_version = '2.1.0';
    public $widget_js_domain = '//w.ahalogy.com';
    public $content_api_domain = 'https://app.ahalogy.com';
    public $content_api_endpoint = 'https://content-submission.ahalogy.com/cms/notify';
    public $date_format = 'c';

  	/**
  	 * @var object Instance of this class
  	 */
  	public static $instance;
    public static $mixpanel_instance;

  	/**
  	 * Get the singleton instance of this class
  	 *
  	 * @return object
  	 */
  	public static function get_instance() {
  		if ( ! ( self::$instance instanceof self ) ) {
  			self::$instance = new self();
  		}

  		return self::$instance;
  	}

    public static function get_mixpanel_instance() {
  		if ( ! ( self::$mixpanel_instance instanceof self ) ) {
  			self::$mixpanel_instance = Mixpanel::getInstance("e7613dd114d4bcd86d8c53d01c381f04");
  		}

  		return self::$mixpanel_instance;
    }

    public function __construct()
    {
      add_filter('plugin_row_meta', array(
        &$this,
        'optionsSetPluginMeta'
      ), 10, 2); // add plugin page meta links

      add_action('admin_init', array(
        &$this,
        'optionsInit'
      )); // whitelist options page

      add_action('admin_menu', array(
        &$this,
        'optionsAddPage'
      )); // add link to plugin's settings page in 'settings' menu on admin menu initilization

      add_action('admin_notices', array(
        &$this,
        'showAdminMessages'
      ));

      add_action('wp_head', array(
        &$this,
        'getAhalogyCode'
      ), 99999);

      add_action('admin_print_styles', array(
        &$this,
        'add_styles'
      ));
    }
 
    public function init() {
      if ($this->isSponsoredContentEnabled()) {
        add_action('manage_posts_custom_column', array(
          &$this,
          'populate_column_view'
        ));

        add_filter('manage_posts_columns', array(
          &$this,
          'add_columns'
        ));

        add_filter( 'manage_edit-post_sortable_columns', array(
          &$this,
          'sortable_licensed_column'
        ));

        add_action('quick_edit_custom_box',  array(
          &$this,
          'add_quick_edit'
        ), 10, 2);

        add_action('admin_footer', array(
          &$this,
          'quick_edit_javascript'
        ));

        add_filter('post_row_actions', array(
          &$this,
          'expand_quick_edit_link'
        ), 10, 2);

        add_filter('add_meta_boxes', array(
          &$this,
          'add_custom_meta_box'
        )); // add custom licensing box in the top right of the post edit page

        add_action('save_post', array(
          &$this,
          'save_post_data'
        ));

        add_action('admin_footer-edit.php', array(
          &$this,
          'custom_bulk_admin_footer'
        ));

        add_action('load-edit.php', array(
          &$this,
          'custom_bulk_action'
        ));

        add_action('admin_notices', array(
          &$this,
          'custom_bulk_admin_notices'
        ));

        add_action('restrict_manage_posts', array(
          &$this,
          'posts_filter_dropdown'
        ));

        add_action('parse_query', array(
          &$this,
          'admin_posts_filter'
        ));
      }

      $this->init_mixpanel();
    }

    function init_mixpanel() {
      // associate client id with mixpanel if available
      if ( $this->hasValidClient() ) {
        $options = $this->optionsGetOptions();
        $mp = ahalogyWP::get_mixpanel_instance();
        $mp->identify($options['client_id']);
      }
    }
 
    function admin_posts_filter( $query )
    {
      global $pagenow;

      if ( is_admin() && $pagenow == 'edit.php' ) {
        if ( isset($_GET['ahalogy_filter']) ) {
          if ( in_array($_GET['ahalogy_filter'], array('removed', 'showcased'))) {
            $query->query_vars['meta_key'] = 'ahalogy_licensed';
            $query->query_vars['meta_value'] = ($_GET['ahalogy_filter'] == 'showcased');
          }
        }
      }
    }
 
    /**
    * Adds a dropdown that allows filtering on the posts SEO Quality.
    *
    * @return void
    */
    public function posts_filter_dropdown() {
      if ( $GLOBALS['pagenow'] === 'upload.php' ) {
        return;
      }

      $current_filter = filter_input( INPUT_GET, 'ahalogy_filter' );

      echo '<select name="ahalogy_filter">
        <option value="">', __( 'All Showcased States', 'ahalogy-wp' ), '</option>';

      $showcased_sel = selected( $current_filter, 'showcased', false );
      $removed_sel = selected( $current_filter, 'removed', false );

      echo '<option ', $showcased_sel, 'value="showcased">Showcased Posts</option>';
      echo '<option ', $removed_sel, 'value="removed">Removed Posts</option>';

      echo '</select>';
    }

 
    public function custom_bulk_admin_footer() {
      global $post_type;
 
      if($post_type == 'post') {
        ?>
        <script type="text/javascript">
          jQuery(document).ready(function() {
            jQuery('<option>').val('showcase').text('<?php _e('Showcase with Ahalogy')?>').appendTo("select[name='action']");
            jQuery('<option>').val('showcase').text('<?php _e('Showcase with Ahalogy')?>').appendTo("select[name='action2']");
            jQuery('<option>').val('remove_showcase').text('<?php _e('Remove from Ahalogy')?>').appendTo("select[name='action']");
            jQuery('<option>').val('remove_showcase').text('<?php _e('Remove from Ahalogy')?>').appendTo("select[name='action2']");
          });
        </script>
        <?php
      }
    }
 
    public function custom_bulk_action() {
      global $typenow;
      $post_type = $typenow;

      if ( $post_type == 'post' ) {

        // get the action
        $wp_list_table = _get_list_table('WP_Posts_List_Table');
        $action = $wp_list_table->current_action();

        $allowed_actions = array('showcase', 'remove_showcase');
        if(!in_array($action, $allowed_actions)) return;

        // // security check
        check_admin_referer('bulk-posts');

        // make sure ids are submitted.  depending on the resource type, this may be 'media' or 'ids'
        if ( isset($_REQUEST['post']) ) {
          $post_ids = array_map('intval', $_REQUEST['post']);
        }

        // this is based on wp-admin/edit.php
        $sendback = remove_query_arg( array('exported', 'untrashed', 'deleted', 'ids'), wp_get_referer() );
        if ( ! $sendback )
          $sendback = admin_url( "edit.php?post_type=$post_type" );
        $sendback = remove_query_arg( array('showcased', 'removed'), $sendback );

        $pagenum = $wp_list_table->get_pagenum();
        $sendback = add_query_arg( 'paged', $pagenum, $sendback );

        // we have no posts, nothing to do
        if ( empty($post_ids) ) {
          wp_redirect($sendback);
          exit;
          return;
        }

        $posts_to_remove = array();

        switch($action) {
          case 'remove_showcase':
            $removed = 0;

            foreach( $post_ids as $post_id ) {
              $post = get_post($post_id);
              if ( $post->post_status == 'draft' || $post->post_status == 'auto-draft' ){
                array_push($posts_to_remove, $post_id);
              }

              $this->updatePostLicensedStatus($post_id, false);
              $removed++;
            }

            // remove relevant posts (drafts)
            $post_ids = array_diff($post_ids, $posts_to_remove);

            $this->bulkNotifyPostsChanged($post_ids);

            // build the redirect url
            $sendback = add_query_arg( array('removed' => $removed, 'ids' => join(',', $post_ids) ), $sendback );
 
          break;

          case 'showcase':
            $showcased = 0;

            foreach( $post_ids as $post_id ) {
              $post = get_post($post_id);
              if ( $post->post_status == 'draft' || $post->post_status == 'auto-draft' ){
                array_push($posts_to_remove, $post_id);
              }

              $this->updatePostLicensedStatus($post_id, true);
              $showcased++;
            }

            // remove relevant posts (drafts)
            $post_ids = array_diff($post_ids, $posts_to_remove);

            $this->bulkNotifyPostsChanged($post_ids);

            // build the redirect url
            $sendback = add_query_arg( array('showcased' => $showcased, 'ids' => join(',', $post_ids) ), $sendback );

          break;
          default: return;
        }

        $sendback = remove_query_arg( array('action', 'action2', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status',  'post', 'bulk_edit', 'post_view'), $sendback );

        // Redirect client
        wp_redirect($sendback);
 
        exit();
      }
    }
 
    public function custom_bulk_admin_notices() {
      global $post_type, $pagenow;
 
      if ( $pagenow == 'edit.php' && $post_type == 'post' ) {

       // posts were showcased
       if ( isset($_REQUEST['showcased']) && intval($_REQUEST['showcased']) > 0 ) {
         $message = sprintf(
           _n( 'Post showcased.', '%s posts showcased with Ahalogy.', $_REQUEST['showcased'] ),
           number_format_i18n( $_REQUEST['showcased'] )
         );
         echo "<div class=\"updated\"><p>{$message}</p></div>";
       }
       // posts were removed from Ahalogy
       else if (isset($_REQUEST['removed']) && (int) $_REQUEST['removed']) {
         $message = sprintf(
           _n( 'Post removed from Ahalogy.', '%s posts removed from Ahalogy.', $_REQUEST['removed'] ),
           number_format_i18n( $_REQUEST['removed'] )
         );
         echo "<div class=\"updated\"><p>{$message}</p></div>";
       }
      }
    }

    public function expand_quick_edit_link($actions, $post) {
      $nonce = wp_create_nonce( 'ahalogy_licensed' . $post->ID);
      $widget_id = get_post_meta( $post->ID, 'ahalogy_licensed', TRUE);
      $actions['inline hide-if-no-js'] = '<a href="#" class="editinline" title="';
      $actions['inline hide-if-no-js'] .= esc_attr( __( 'Edit this item inline' ) ) . '" ';
      $actions['inline hide-if-no-js'] .= " onclick=\"set_inline_widget_set('{$widget_id}', '{$nonce}')\">";
      $actions['inline hide-if-no-js'] .= __( 'Quick&nbsp;Edit' );
      $actions['inline hide-if-no-js'] .= '</a>';
      return $actions;
    }

    function quick_edit_javascript() {
      global $current_screen;
      if (($current_screen->id != 'edit-post') || ($current_screen->post_type != 'post')) return;

      ?>
      <script type="text/javascript">
      <!--
      function set_inline_widget_set(licensed, nonce) {
          // revert Quick Edit menu so that it refreshes properly
          inlineEditPost.revert();
          console.log(inlineEditPost);
          var licensedInput = document.getElementById('ahalogy_licensed');
          var nonceInput = document.getElementById('ahalogy_quick_edit_nonce');
          nonceInput.value = nonce;
          licensedInput.checked = licensed;
      }
      //-->
      </script>
      <?php
    }

    public function post_edit_meta_box_markup($object)
    {
      wp_nonce_field(basename(__FILE__), "ahalogy_edit_post_nonce" . $object->ID);

      $checked = "";
      if ( get_post_meta($object->ID, "ahalogy_licensed", true) || $object->post_status == 'auto-draft' ){
        $checked = " checked";
      }

      ?>
        <input id="ahalogy_licensed" name="ahalogy_licensed" type="checkbox" value="1"<?php echo $checked; ?>/>
        <span class="title">Showcase with Ahalogy</span>
      <?php
    }

    public function add_custom_meta_box()
    {
      wp_enqueue_style('ahalogy');
      add_meta_box("ahalogy-licensed-box", "Ahalogy", array($this, "post_edit_meta_box_markup"), "post", "side", "high", null);
    }

    public function save_post_data($post_id){
      // verify if this is an auto save routine. If it is our form has not been submitted,
      // so we dont want to do anything
      if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
        return $post_id;
      }

      // Check permissions
      if ( 'page' == $_POST['post_type'] ) {
        if ( !current_user_can( 'edit_page', $post_id ) )
            return $post_id;
      } else {
        if ( !current_user_can( 'edit_post', $post_id ) )
        return $post_id;
      }

      // OK, we're authenticated: we need to find and save the data
      $post = get_post($post_id);
      if ($post->post_type != 'revision') {
        $this->updatePostLicensedStatus($post_id, ($_POST['ahalogy_licensed'] == "1"));
      }

      return $post_id;
    }

    public function add_quick_edit($column_name, $post_type) {
      if ($column_name != 'licensed') return;

      wp_enqueue_style('ahalogy');

      static $printNonce = TRUE;
        if ( $printNonce ) {
          $printNonce = FALSE;
          wp_nonce_field( plugin_basename( __FILE__ ), 'ahalogy_quick_edit_nonce' );
        }
      
      global $post;
      ?>
      <fieldset class="inline-edit-col-left">
      <div class="inline-edit-col">
        <label class="alignleft">
          <input id="ahalogy_licensed" name="ahalogy_licensed" type="checkbox" value="1"/>
          <span class="checkbox-title">Showcase with Ahalogy</span>
        </label>
      </div>
      </fieldset>
      <?php
    }

    public function sortable_licensed_column( $columns ) {
      $columns['licensed'] = 'licensed';
      return $columns;
    }

    public function populate_column_view($name) {
      global $post;
      switch ($name) {
        case 'licensed':
          if ( get_post_meta($post->ID, 'ahalogy_licensed', true) ){
            echo '<span class="sponsoredContentIndicator sponsoredContentIndicator--showcased"></span> Showcased';
          }
          else {
            echo '<span class="sponsoredContentIndicator sponsoredContentIndicator--removed"></span> Removed';
          }
      }
    }

    public function add_columns($columns) {
      $columns['licensed'] = "Ahalogy";
      return $columns;
    }

    public function add_styles()
    {
      wp_register_style('ahalogy', plugins_url('/css/ahalogy.css', __FILE__));
    }

    // load i18n textdomain
    public function loadTextDomain()
    {
      load_plugin_textdomain($this->plugin_textdomain, false, trailingslashit(dirname(plugin_basename(__FILE__))) . 'lang/');
    }

    // get default plugin options
    public function optionsGetDefaults()
    {
      $defaults = array(
        'client_id' => '',
        'sponsored_content_optin' => false
      );

      return $defaults;
    }

    public function hasValidClient()
    {
      $options = $this->optionsGetOptions();

      return isset($options) && isset($options['client_id']) && $this->isClientIdValid($options['client_id']);
    }

    public function isSponsoredContentEnabled()
    {
      $options = $this->optionsGetOptions();

      return isset($options) && isset($options['sponsored_content_optin']) && ($options['sponsored_content_optin']);
    }

    public function optionsGetOptions()
    {
      return get_option($this->options_name, $this->optionsGetDefaults());
    }

    // set plugin links
    public function optionsSetPluginMeta($links, $file)
    {
      $plugin = plugin_basename(__FILE__);
      if ($file == $plugin) { // if called for THIS plugin then:
        $newlinks = array(
          '<a href="options-general.php?page=' . $this->options_page . '">' . __('Settings', $this->plugin_textdomain) . '</a>'
        ); // array of links to add
        return array_merge($links, $newlinks); // merge new links into existing $links
      }

      return $links; // return the $links (merged or otherwise)
    }

    // plugin startup
    public function optionsInit()
    {
      register_setting($this->options_group, $this->options_name, array(
        &$this,
        'optionsValidate'
      ));

      $options = $this->optionsGetOptions();

      if (false === $options || !isset($options['plugin_version']) || $options['plugin_version'] != $this->plugin_version) {
        $this->clearCache();

        // unlikely, but just in case
        if ( !is_array( $options ) )
          $options = array();

        if (is_array($options)) {
          // store the new version
          $options['plugin_version'] = $this->plugin_version;
          delete_option('ahalogy_snippet_last_request');
          delete_option('ahalogy_js_template');
          delete_option('insert_code');
          delete_option('mobilify_optin');
          delete_option('location');
          delete_option('mobilify_api_optin');
          delete_option('google_analytics_id');

          update_option($this->options_name, $options);
        }
      }
    }

    public static function setSponsoredContentProgram($value) {
      update_option('ahalogy_sponsored_content_enabled', $value);
    }

    public static function inSponsoredContentProgram() {
      return get_option('ahalogy_sponsored_content_enabled') == 1;
    }

    public static function is_post_licensed($post_id) {
      return get_post_meta($post_id, 'ahalogy_licensed', true) == 1;
    }

    public function updatePostLicensedStatus($post_id, $value) {
      $previous_value = $this->is_post_licensed($post_id);
      update_post_meta( $post_id, 'ahalogy_licensed', $value);

      if ( $previous_value != $value ) {
        $mp = ahalogyWP::get_mixpanel_instance();
        $mp->track("Post Licensing State Changed",
          array(
            "Post ID" => $post_id,
            "Post Title" => get_the_title($post_id),
            "Prior Licensing State" => $previous_value,
            "New Licensing State" => $value,
            "Settings" => $this->optionsGetOptions()
          )
        );
      }
    }

    public function bulkNotifyPostsChanged($post_ids) {
      $site_posts = array();
      foreach($post_ids as $post_id ) {
        array_push($site_posts, array(
          'site_post_identifier' => $post_id,
          'licensed' => $this->is_post_licensed($post_id),
          'url' => get_permalink($post_id)
        ));
      }

      // construct the message + send it
      if ( count($site_posts) > 0 ) {
        $options = $this->optionsGetOptions();

        $post_contents = array(
          'v' => '1.0',
          'plugin_version' => $this->plugin_version,
          'client_id' => $options['client_id'],
          'domain' => $_SERVER['SERVER_NAME'],
          'site_posts' => $site_posts
        );

        $response = wp_remote_post($this->content_api_endpoint, array(
          'method' => 'POST',
          'timeout' => 5,
          'redirection' => 5,
          'httpversion' => '1.0',
          'blocking' => true,
          'headers' => array(),
          'body' => array(
            'notify' => json_encode($post_contents)
          ),
          'cookies' => array()
        ));
      }
    }

    public function notifyAllPostsLicensed() {
      // We only care about the loading of options-general.php.  If we don't do this check then
      // we will execute notifyAllPostsLicensed twice. Given options.php runs first, then
      // options-general.php is loaded. And options.php won't have the settings actually saved yet.
      if ( !empty($GLOBALS['pagenow']) && $GLOBALS['pagenow'] == 'options-general.php' ) {
        $options = $this->optionsGetOptions();

        $page = 1;

        while ( true )
        {
          $args = array(
            'paged' => $page,
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'modified',
            'order' => 'DESC',
            'post_type' => 'post'
          );

          $the_query = new WP_Query($args);

          // done
          if ( !$the_query->have_posts() ){
            wp_reset_postdata();
            break;
          }

          // find all of the posts
          $post_ids = array();
          while ($the_query->have_posts()) {
            $the_query->the_post();
            global $post;
            array_push($post_ids, $post->ID);

            // license the post
            $this->updatePostLicensedStatus($post->ID, true);
          }

          $this->bulkNotifyPostsChanged($post_ids);

          $page++;

          wp_reset_postdata();
        }
      }
    }

    public function notifySettingsSaved() {
      // We only care about the loading of options-general.php.  If we don't do this check then
      // we will execute notifySettingsSaved twice. Given options.php runs first, then
      // options-general.php is loaded. And options.php won't have the settings actually saved yet.
      if ( !empty($GLOBALS['pagenow']) && $GLOBALS['pagenow'] == 'options-general.php' ) {
        global $wp_version;
        $options = $this->optionsGetOptions();

        // add additional info
        if (is_array($options)) {
          $options['plugin_version'] = $this->plugin_version;
          $options['domain'] = $_SERVER['SERVER_NAME'];
          $options['v'] = '1.0';
          $options['wp_version'] = $wp_version;
        }

        $url = $this->content_api_domain . '/api/wordpress/client/' . $options['client_id'] . '/settings_changed';
        $response = wp_remote_post($url, array(
          'method' => 'POST',
          'timeout' => 5,
          'redirection' => 5,
          'httpversion' => '1.0',
          'blocking' => true,
          'headers' => array(),
          'body' => array(
            'settings' => $options
          ),
          'cookies' => array()
        ));

        // store if they are in the program
        if (!is_wp_error($response)) {
          $json = json_decode($response['body']);
          ahalogyWP::setSponsoredContentProgram($json->sponsored_content);
        }

        $response = wp_remote_post($this->content_api_endpoint, array(
          'method' => 'POST',
          'timeout' => 5,
          'redirection' => 5,
          'httpversion' => '1.0',
          'blocking' => true,
          'headers' => array(),
          'body' => array(
            'notify' => json_encode($options)
          ),
          'cookies' => array()
        ));
      }
    }

    // create and link options page
    public function optionsAddPage()
    {
      add_options_page($this->plugin_name . ' ' . __('Settings', $this->plugin_textdomain), __('Ahalogy', $this->plugin_textdomain), 'manage_options', $this->options_page, array(
        &$this,
        'optionsDrawPage'
      ));
    }

    public function isClientIdValid($client_id)
    {
      return preg_match('/\A\d{10,11}(-[-a-zA-Z0-9_\.]+)?/', $client_id);
    }

    // sanitize and validate options input
    public function optionsValidate($input)
    {
      $client_id = wp_filter_nohtml_kses(trim($input['client_id']));

      if (preg_match('/\d{10,11}/', $client_id, $matches)) {
        $input['client_id'] = $matches[0];
      } else {
        $input['client_id'] = null;
      }

      if (!isset($input['sponsored_content_optin'])) {
        $input['sponsored_content_optin'] = false;
      }
      else if ( $input['sponsored_content_optin'] == '1' ) {
        $input['sponsored_content_optin'] = true;
      }

      // Check if the sponsored_content_optin has changed or not
      $current_options = $this->optionsGetOptions();

      // Check if client_id has changed. If so, clear any content cache
      if ($current_options['client_id'] && $current_options['client_id'] != $input['client_id']) {
        delete_option('ahalogy_js_template');
      }

      $this->notifySettingsSaved();

      if ( $input['sponsored_content_optin'] ) {
        $this->notifyAllPostsLicensed();
      }

      // let mixpanel know
      if ( !empty($GLOBALS['pagenow']) && $GLOBALS['pagenow'] == 'options.php' ) {
        $mp = ahalogyWP::get_mixpanel_instance();
        $mp->track("Settings Saved",
          array(
            "Prior Settings" => $current_options,
            "New Settings" => $input
          )
        );
      }

      return $input;
    }

    // draw the options page
    public function optionsDrawPage()
    {
      wp_enqueue_style('ahalogy');

      $options = $this->optionsGetOptions();
?>

<div class="wrap">
    <div class="ahalogy_content_wrapper">
        <div class="ahalogy_content_cell">
            <h1>Ahalogy Settings</h1>

            <form method="post" action="options.php">
                <?php
      // nonce, lulsz php
      settings_fields($this->options_group);
      do_settings_sections($this->options_group);
?>

                <p>
                    <h2>Client ID</h2>
                    To securely connect your site to your Ahalogy account, please enter the Client ID string of numbers provided in your Pinning Settings page of Ahalogy. <a href='https://help.ahalogy.com/customer/en/portal/articles/1821494-how-to-install-the-code-snippet'>Learn more</a>
                </p>

                <div class="container">
                    <div class="label">
                        <label class="checkbox" for="<?php
      echo $this->options_name;
?>[client_id]"><?php
      _e('Client ID', $this->plugin_textdomain);
?>:</label>
                    </div>
                    <div class="right">
                        <input class="textinput" type="text" autofocus="autofocus" placeholder="123456789" id="<?php
      echo $this->options_name;
?>[client_id]" name="<?php
      echo $this->options_name;
?>[client_id]" value="<?php
      echo $options['client_id'];
?>" maxlength="30" />
                        <p class="aside">Don't know your id? No sweat, <a href="https://app.ahalogy.com/settings/pinning/code-snippet" target="_blank">we've got it for you here</a>.</p>
                        <p class="aside">Not an Ahalogy Content Partner yet? <a href='https://www.ahalogy.com/publishers'>Apply to join today.</a></p>
                    </div>
                </div>

                <br>

                <p>
                    <h2>Sponsored Content Program</h2>
                    Once you've joined Ahalogy's Sponsored Content Program, opting in here lets you showcase your content to our Brand Partners to help you achieve sponsored content deals. <a href='https://help.ahalogy.com/customer/en/portal/articles/2312831-introducing-ahalogy-s-sponsored-content-program'>Learn More</a>
                </p>

                <div class="container">
                    <div class="label">
                        <label
                            class="checkbox"
                            for="<?php echo $this->options_name; ?>[sponsored_content_optin]"
                        >
                            <?php _e('Content Showcase', $this->plugin_textdomain); ?>:
                        </label>
                    </div>
                    <div class="right">
                        <input
                            style="margin:0;"
                            id="<?php echo $this->options_name; ?>[sponsored_content_optin]"
                            name="<?php echo $this->options_name; ?>[sponsored_content_optin]"
                            type="checkbox"
                            value="1"
                            <?php checked($options['sponsored_content_optin'], 1); ?>
                        />
                        Enable automatic showcase of my posts to Ahalogy's Brand Partners and agree to <a href="https://www.ahalogy.com/terms" target="_blank" alt="Ahalogy Terms of Service" title="Ahalogy Terms of Service">Ahalogy Terms of Service</a>.
                        <p class="aside sub-text">
                            To remove specific content, select Edit or Quick Edit from the Posts tab.
                        </p>
                    </div>
                </div>

                <br><br>

                <p>
                    <input type="submit" class="button-primary" value="<?php
      _e('Save Changes', $this->plugin_textdomain);
?>" />
                </p>
            </form>
        </div>
    </div>
</div>
		<?php

    }

    // 	the Ahalogy widget code to be inserted
    public function getAhalogyCode()
    {
      $options = $this->optionsGetOptions();

      if ( strlen($options['client_id']) > 9 )
      {
        echo sprintf('
<script data-cfasync="false" type="text/javascript"> /* generated by Ahalogy wordpress plugin [version %1$s] */
  (function(a,h,a_,l,o,g,y){
  window[a_]={c:o,b:g,u:l};var s=a.createElement(h);s.src=l,e=a.getElementsByTagName(h)[0];e.parentNode.insertBefore(s,e);
  })(document,"script","_ahalogy","%3$s/",{client:"%2$s"});
</script>
', $this->plugin_version, $options['client_id'], $this->widget_js_domain);
      }
      else
      {
        echo sprintf('
<!--
Ahalogy wordpress plugin [version %1$s] is installed but Client ID not set
-->
', $this->plugin_version);
      }
    }

    /**
     * Generic function to show a message to the user using WP's 
     * standard CSS classes to make use of the already-defined
     * message colour scheme.
     *
     * @param $message The message you want to tell the user.
     * @param $errormsg If true, the message is an error, so use 
     * the red message style. If false, the message is a status 
     * message, so use the yellow information message style.
     */
    public function showMessage($message, $errormsg = false)
    {
      if ($errormsg) {
        echo '<div id="message" class="error">';
      } else {
        echo '<div id="message" class="updated fade">';
      }

      echo "<p><strong>$message</strong></p></div>";
    }

    public function isOnAhalogySettingsPage()
    {
      global $pagenow;

      return $pagenow == 'options-general.php' && isset($_GET['page']) && $_GET['page'] == 'ahalogy_wp';
    }

    /**
     * Just show ClientID error message if necessary.
     */
    public function showAdminMessages()
    {

      //Show a message on all admin pages if the client id is not set
      $options = get_option($this->options_name, $this->optionsGetDefaults());

      if (empty($options['client_id'])) {
        // Only show to admins
        if (current_user_can('manage_options')) {
          $this->showMessage("Please <a href='" . admin_url('options-general.php?page=ahalogy_wp') . "'>enter your client ID</a> to activate the Ahalogy plugin.", true);
        }
      } elseif (!$this->isClientIdValid($options['client_id'])) {
        if (current_user_can('manage_options')) {
          $this->showMessage("Please <a href='" . admin_url('options-general.php?page=ahalogy_wp') . "'>update your client ID</a> to activate the Ahalogy plugin.", true);
        }
      }
    }

    public static function clearCache()
    {
      //Remove our options
      //delete_option('ahalogy_snippet_last_request');
      //delete_option('ahalogy_js_template');       

      // Check for W3 Total Cache
      if (function_exists('w3tc_pgcache_flush')) {
        w3tc_pgcache_flush();
      }

      // Check for WP Super Cache
      if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
      }
    }
  } // end class

  register_activation_hook(__FILE__, array(
    'ahalogyWP',
    'clearCache'
  ));

  // Initialize
  ahalogyWP::get_instance()->init();

  include_once dirname(__FILE__) . '/Ahalogy-wp-content.php';
  include_once dirname(__FILE__) . '/Ahalogy-wp-content-post.php';
  include_once dirname(__FILE__) . '/Ahalogy-wp-content-author.php';
  include_once dirname(__FILE__) . '/Ahalogy-wp-content-attachment.php';
endif; // end collision check
