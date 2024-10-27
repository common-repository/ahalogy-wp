<?php

if ( ! function_exists( 'add_filter' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}

class Content_JSON_API_Author
{
  public $id; // Integer
  public $slug; // String
  public $name; // String
  public $first_name; // String
  public $last_name; // String
  public $nickname; // String
  public $url; // String
  public $description; // String

  public function Content_JSON_API_Author($id = null)
  {
    if ($id) {
      $this->id = (int) $id;
    } else {
      $this->id = (int) get_the_author_meta('ID');
    }
    $this->set_value('slug', 'user_nicename');
    $this->set_value('name', 'display_name');
    $this->set_value('first_name', 'first_name');
    $this->set_value('last_name', 'last_name');
    $this->set_value('nickname', 'nickname');
    $this->set_value('url', 'user_url');
    $this->set_value('description', 'description');
  }

  public function set_value($key, $wp_key = false)
  {
    if (!$wp_key) {
      $wp_key = $key;
    }
    $this->$key = html_entity_decode(get_the_author_meta($wp_key, $this->id), ENT_COMPAT, 'UTF-8');
  }
}
