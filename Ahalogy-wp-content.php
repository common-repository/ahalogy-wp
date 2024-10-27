<?php

if ( ! function_exists( 'add_filter' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}

if (!class_exists('ahalogyWPContent')): // namespace collision check
  class ahalogyWPContent
  {
    ////////////////////////////
    // Content functions
    // JSON API functionality adapted from JSON API plugin by Dan Phiffer (http://wordpress.org/plugins/json-api/)
    ////////////////////////////    

    // constructor
    public function ahalogyWPContent()
    {
      add_action('template_redirect', array(
        &$this,
        'jsonTemplateRedirect'
      ));

      if (ahalogyWP::get_instance()->isSponsoredContentEnabled()) {
        add_action('admin_init', array(
          &$this,
          'verifyPHPVersion'
        ));
        add_action('save_post', array(
          &$this,
          'notifyPostSaved'
        ));
      }
    }

    // Ping ahalogy when a post is saved or updated.
    public function notifyPostSaved($post_id)
    {
      $options = ahalogyWP::get_instance()->optionsGetOptions();

      if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
        return $post_id;
      }

      if (isset($_POST['post_type'])) {
        // only care about a post
        if ($_POST['post_type'] != 'post') {
          return $post_id;
        }

        // don't send an event for drafts
        $post = get_post($post_id);
        if ( $post->post_status == 'draft' || $post->post_status == 'auto-draft' || $post->post_status == 'inherit' ){
          return $post_id;
        }

        // Here is where we will ping ahalogy's servers with new post information.
        if (ahalogyWP::get_instance()->hasValidClient() && ahalogyWP::get_instance()->isSponsoredContentEnabled()) {

          $post_contents = array(
            'v' => '1.0',
            'plugin_version' => ahalogyWP::get_instance()->plugin_version,
            'client_id' => $options['client_id'],
            'domain' => $_SERVER['SERVER_NAME'],
            'site_posts' => array(
              array(
                'site_post_identifier' => $post_id,
                'licensed' => ahalogyWP::is_post_licensed($post_id),
                 'url' => get_permalink($post_id)
              )
            )
          );

          $response = wp_remote_post(ahalogyWP::get_instance()->content_api_endpoint, array(
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

          if (is_wp_error($response)) {
            //Setting the error but we won't do anything with it for now.
            $error_message = $response->get_error_message();
            if ($print_debug_comment) {
              echo '<!-- Ahalogy check for Ahalogy API notify change post: ' . $response->get_error_message() . ' -->';
            }
          }
        }
      }
    }

    // Verify we can use native JSON functions
    public function verifyPHPVersion()
    {
      if (phpversion() < 5) {
        add_action('admin_notices', array(
          &$this,
          'ahalogyPHPWarning'
        ));

        return;
      }
    }

    // Check for PHP version 5 or greater
    public function ahalogyPHPWarning()
    {
      echo '<div id="json-api-warning" class="updated fade"><p>Sorry, the Ahalogy plugin requires PHP version 5.0 or greater.</p></div>';
    }

    public function getPostResponse($post) {
      $options = ahalogyWP::get_instance()->optionsGetOptions();

      if ( $post )
      {
        return array(
          'post' => new Content_JSON_API_Post($post)
        );
      }

      header("HTTP/1.0 404 Not Found");
      exit;
    }

    public function getPostStatus($post) {
      if ( $post ) {
        $post_output = array();
        $post_output['id']       = $post->ID;
        $post_output['title']    = $post->post_title;
        $post_output['url']      = get_permalink($post->ID);
        $post_output['modified'] = date(ahalogyWP::get_instance()->date_format, strtotime($post->post_modified));
        $post_output['licensed'] = ahalogyWP::is_post_licensed($post->ID);
        return $post_output;
      }

      header("HTTP/1.1 404 Not Found");
      exit;
    }

    public function redirectForPostIfNeeded($post) {
      if ( isset($_REQUEST['redirect']) && intval($_REQUEST['redirect']) == 1 ) {
        if ( $post ) {
          $url = get_permalink($post->ID);
          header("Location: $url");
          exit;
        }
        else
        {
          header("HTTP/1.1 404 Not Found");
          exit;
        }
      }
    }

    //redirect to JSON template if necessary
    public function jsonTemplateRedirect()
    {
      // Compatibility with Disqus plugin
      remove_action('loop_end', 'dsq_loop_end');

      $options = ahalogyWP::get_instance()->optionsGetOptions();

      //Check if it's the homepage for site wide API calls
      if (is_front_page()) {

        //Check for initial API request
        if ((isset($_REQUEST['content_json'])) && ($_REQUEST['content_json'] == 1)) {

          //The individual post API
          if ( isset($_REQUEST['content']) && isset($_REQUEST['id']) ){
            $post_id = intval($_REQUEST['id']);
            $post = get_post($post_id);
            $this->redirectForPostIfNeeded($post);
            $this->respond( $this->getPostResponse($post) );
          }

          //Status of an individual post
          if ( isset($_REQUEST['content_status']) && isset($_REQUEST['id']) ){
            $post_id = intval($_REQUEST['id']);
            $post = get_post($post_id);

            $this->redirectForPostIfNeeded($post);

            $post_status = $this->getPostStatus($post);
            $this->respond($post_status);
          }

          //Ahalogy plugin settings query
          if ((isset($_REQUEST['ahalogy_settings_index'])) && ($_REQUEST['ahalogy_settings_index'] == 1)) {

            //Build settings array
            $response                   = array();
            $response['plugin_version'] = ahalogyWP::get_instance()->plugin_version;

            if (isset($options)) {
              foreach ($options as $key => $value) {
                $response[$key] = $value;
              }
            }

            $this->respond($response);
          }

          //The Index API. content_json is true. Check the method
          if ((isset($_REQUEST['content_index'])) && ($_REQUEST['content_index'] == 1)) {

            $response = array();

            //Pagination
            $paged = (isset($_GET['page']) && $_GET['page'] !== '') ? $_GET['page'] : 1;
            $count = (isset($_GET['rpp']) && $_GET['rpp'] !== '') ? $_GET['rpp'] : 100;

            $post_types = array();

            if (isset($_GET['post_types']) && $_GET['post_types']) {
              $post_type = explode(',', $_GET['post_types']);
            } else {
              $post_type = 'post';
            }

            // Arguments for WP_Query
            $args = array(
              'posts_per_page' => $count,
              'post_status' => 'publish',
              'paged' => $paged,
              'orderby' => 'modified',
              'order' => 'DESC',
              'post_type' => $post_type
            );

            //Check for modified_since date parameter
            if (isset($_REQUEST['modified_since'])) {
              $modifieddate = $_REQUEST['modified_since'];

              if ($this->is_timestamp($modifieddate)) {
                $moddatearray = getdate($modifieddate);

                $args['date_query'] = array(
                  'column' => 'post_modified_gmt',
                  'after' => array(
                    'year' => $moddatearray['year'],
                    'month' => $moddatearray['mon'],
                    'day' => $moddatearray['mday']
                  )
                );
              }
            }

            $the_query = new WP_Query($args);

            if ($the_query->have_posts()) {
              $response = array();

              $response['page'] = $paged;
              $response['rpp']  = $count;

              while ($the_query->have_posts()) {
                $the_query->the_post();
                global $post;
                $response['posts'][]    = $this->getPostStatus($post);
              }
            } else {
              $response = array(
                'status' => 'no results'
              );
            }

            $this->respond($response);
            exit;
          }
        }
      }
    }

    public function get_json($data, $status = 'ok')
    {
      // Include a status value with the response
      // Include plugin version with the response
      $options = ahalogyWP::get_instance()->optionsGetOptions();

      if (is_array($data)) {
        $data = array_merge(array(
          'status' => $status,
          'sponsored_content_optin' => $options['sponsored_content_optin']
        ), array(
          'plugin_version' => ahalogyWP::get_instance()->plugin_version
        ), $data);
      } elseif (is_object($data)) {
        $data = get_object_vars($data);
        $data = array_merge(array(
          'status' => $status
        ), $data);
      }

      if (function_exists('json_encode')) {
        // Use the built-in json_encode function if it's available
        $json = json_encode($data);
      } else {
        // Use PEAR's Services_JSON encoder otherwise
        if (!class_exists('Services_JSON')) {
          require_once dirname(__FILE__) . '/library/JSON.php';
        }
        $json_service = new Services_JSON();
        $json         = $json_service->encode($data);
      }

      return $json;
    }

    // JSON Output
    public function output($result)
    {
      $charset = get_option('blog_charset');
      header('HTTP/1.1 200 OK', true);
      header("Content-Type: application/json; charset=$charset", true);
      echo $result;
    }

    public function respond($result, $status = 'ok')
    {
      $json = $this->get_json($result, $status);

      // just in case other plugins have printed things to the buffer already
      ob_clean();

      // Output the result
      $this->output($json);
      exit;
    }

    //Validate our modified_date timestamp
    public function is_timestamp($timestamp)
    {
      $check = (is_int($timestamp) or is_float($timestamp)) ? $timestamp : (string) (int) $timestamp;

      return ($check === $timestamp) and ((int) $timestamp <= PHP_INT_MAX) and ((int) $timestamp >= ~PHP_INT_MAX);
    }
  } //end class
endif;

$ahalogyWPContent_instance = new ahalogyWPContent();
