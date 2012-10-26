<?php
class Plugin_commentatic extends Plugin {

  public $meta = array(
    'name'       => 'Commentatic',
    'version'    => '1.0',
    'author'     => 'Olivier Gorzalka', // form part inspired by Eric Barnes (https://github.com/ericbarnes/Statamic-email-form)
    'author_url' => 'http://clearideaz.com'
  );
  
  public $total_items = false; // total comments
  public $total_pages = false; // total pages of comments
  public $user_datas = false; // data of logged user
  
  protected $comment_folder = ''; // comment path from _content
  protected $raw_comment_folder = ''; // raw comment path including base path
  protected $comment_file = ''; // path relative to the comment file
  protected $form_datas;
  protected $app;
  protected $config = array(); // plugin config
  protected $options = array(); // options
  protected $validation = array(); // validation errors
  
  /**
   * init the Slim App and setup the paths
   */
  protected function init() {
    $this->config = Spyc::YAMLLoad('_config/add-ons/commentatic.yaml');

    $this->app = \Slim\Slim::getInstance(); // app instance

    if (!array_key_exists('email_field', $this->config)) {
      throw new Exception('Hey, don\'t forget to copy the commentatic.yaml file from the plugin folder to _config/add-ons/commentatic.yaml');
    }
    
    // comment locations
    $this->get_comment_path();

    $this->options['class'] = $this->fetch_param('class', '');
    $this->options['id'] = $this->fetch_param('id', '');
    
    $this->options['required'] = $this->fetch_param('required');
    $this->options['honeypot'] = $this->fetch_param('honeypot', true); #boolen param
  }
  
  /**
   * Default methods
   */
  
  // comment form 
  public function form() {
    $this->init();
    

    // Set up some default vars.
    $output = '';
    $vars = array(array());
    
    $flash = $this->app->view()->getData('flash');

    if ($flash['success']) {
      $vars = array(array('success' => true));
    }
    
    // If the page has post data process it.
    if ($this->app->request()->isPost()) {
      $this->form_datas = (object)$this->app->request()->post();
      if ($this->logged_in()) {
        $this->form_datas->{$this->config['username_field']} = $this->username();
        $this->form_datas->{$this->config['email_field']} = $this->email();
      }
      
      if ($this->config['comment_field'] != 'content') {
        $this->form_datas->content = $this->form_datas->{$this->config['comment_field']};
        unset($this->form_datas->{$this->config['comment_field']});
      }
      
      if ( ! $this->validate()) {
        $vars = array(array('error' => true, 'errors' => $this->validation));
      } elseif ($this->save()) {
        $this->app->flash('success', 'Comment sent successfully !');
        $url_redirect = rtrim(Statamic::get_site_root(), '/').$this->app->request()->getResourceUri();
        if ($this->page_count() > 0 && $this->fetch_param('sort_dir', 'asc') != 'desc') {
          $url_redirect .= '?page='.$this->page_count();
        }
        if( $this->options['id'] != '') {
          $url_redirect .= '#'.$this->options['id'];
        }
        $this->app->redirect($url_redirect, 302);
      } else {
        $vars = array(array('error' => true, 'errors' => 'Could not send comment'));
      }
    }
    
    // Display the form on the page.
    $output .= '<form method="post"';
    $output .= ' action="' . rtrim(Statamic::get_site_root(), '/').$this->app->request()->getResourceUri().'"';
    if ( $this->options['class'] != '') { $output .= ' class="' . $this->options['class'] . '"'; }
    if ( $this->options['id'] != '') { $output .= ' id="' . $this->options['id'] . '"'; }
    $output .= '>';
    
    //inject the honeypot if true
    if ($this->options['honeypot']) {
      $output .= '<input type="text" class="honeypot" name="wearetherobot" value="" />';
    }
    
    $output .= $this->parse_loop($this->content, $vars);
    $output .= '</form>';
    
    //inject the honeypot if true
    if ($this->options['honeypot']) {
      $output .= '<style>.honeypot { display:none; }</style>';
    }

    return $output;
  }
  
  // Comment listing method
  public function listing() {
    
    $this->init();
    
    $folder      = $this->fetch_param('folder', $this->comment_folder); // defaults to null
    if ($folder != $this->comment_folder) {
      $this->get_comment_path();
      $folder = $this->comment_folder; // resolve new folder path
    }
    
    $limit       = $this->config['comment_per_page']; // defaults to none
    $offset      = $this->fetch_param('offset', 0, 'is_numeric'); // defaults to zero
    $show_future = $this->fetch_param('show_future', false, false, true); // defaults to no
    $sort_by     = $this->fetch_param('sort_by', 'date'); // defaults to date
    $sort_dir    = $this->fetch_param('sort_dir', 'asc'); // defaults to desc
    $conditions  = $this->fetch_param('conditions', null, false, false, false); // defaults to null
    $slug        = $this->fetch_param('slug', null); // defaults to null
    $switch      = $this->fetch_param('switch', null); // defaults to null
    $since       = $this->fetch_param('since', null); // defaults to null
    $until       = $this->fetch_param('until', null); // defaults to null
    $paginate    = $this->fetch_param('paginate', true, false, true); // defaults to true

    if ($this->config['comment_per_page'] && $paginate) {
      // override limit/offset if paging
      $pagination_variable = Statamic::get_pagination_variable();
      $page = $this->app->request()->get($pagination_variable) ? $this->app->request()->get($pagination_variable) : 1;
      $offset = (($page * $this->config['comment_per_page']) - $this->config['comment_per_page']) + $offset;
    }

    if ($this->comment_folder) {
      $list = Statamic::get_content_list($folder, $limit, $offset, false, true, $sort_by, $sort_dir, $conditions, $switch, false, false, $since, $until);
      if (sizeof($list)) {

        foreach ($list as $key => $item) {
        
          /* Begin Gravatar Support */
          $gravatar_src = 'http://www.gravatar.com/avatar/';
          $gravatar_href = 'http://www.gravatar.com/';
          $gravatar_alt = '';

          if (array_key_exists($this->config['email_field'], $item)) {
            $gravatar_src .= md5( strtolower( trim( $item[$this->config['email_field']] ) ) );
            $gravatar_href .= md5( strtolower( trim( $item[$this->config['email_field']] ) ) );
            $gravatar_alt = $item[$this->config['email_field']].'\'s Gravatar';
          }

          $list[$key]['avatar'] = "<img src=\"$gravatar_src\" alt=\"$gravatar_alt\" />";
          $list[$key]['gravatar_profile'] = $gravatar_href;
          /* End Gravatar Support */
          
          $list[$key]['content'] = Statamic::parse_content($item['content'], $item);
        }
        return $this->parse_loop($this->content, $list);
      } else {
        return array('no_results' => true);
      }
      
    }
    return array();
  }
  
  // Pagination method
  public function pagination() {
    $this->init();

    $folder      = $this->fetch_param('folder', $this->comment_folder); // defaults to null
    if ($folder != $this->comment_folder) {
      $this->get_comment_path();
      $folder = $this->comment_folder; // resolve new folder path
    }
    
    $limit       = $this->config['comment_per_page']; // defaults to none
    $show_future    = $this->fetch_param('show_future', false, false, true); // defaults to no
    $show_past      = $this->fetch_param('show_past', true, false, true); // defaults to yes
    $conditions     = $this->fetch_param('conditions', null, false, false, false); // defaults to null
    $since          = $this->fetch_param('since', null); // defaults to null
    $until          = $this->fetch_param('until', null); // defaults to null

    $style = $this->fetch_param('style', 'prev_next'); // defaults to date
    $count = Statamic::get_content_count($this->comment_folder, false, true, $conditions, $since, $until);

    $pagination_variable = Statamic::get_pagination_variable();
    $page = $this->app->request()->get($pagination_variable) ? $this->app->request()->get($pagination_variable) : 1;
    
    $arr = array();
    $arr['total_items']        = (int) max(0, $count);
    $arr['items_per_page']     = (int) max(1, $limit);
    $arr['total_pages']        = (int) ceil($count / $limit);
    $arr['current_page']       = (int) min(max(1, $page), max(1, $page));
    $arr['current_first_item'] = (int) min((($page - 1) * $limit) + 1, $count);
    $arr['current_last_item']  = (int) min($arr['current_first_item'] + $limit - 1, $count);
    $arr['previous_page']      = ($arr['current_page'] > 1) ? "?{$pagination_variable}=".($arr['current_page'] - 1) : FALSE;
    $arr['next_page']          = ($arr['current_page'] < $arr['total_pages']) ? "?{$pagination_variable}=".($arr['current_page'] + 1) : FALSE;
    $arr['first_page']         = ($arr['current_page'] === 1) ? FALSE : "?{$pagination_variable}=1";
    $arr['last_page']          = ($arr['current_page'] >= $arr['total_pages']) ? FALSE : "?{$pagination_variable}=".$arr['total_pages'];
    $arr['offset']             = (int) (($arr['current_page'] - 1) * $limit);

    return $this->parser->parse($this->content, $arr);
  }
  
  /**
  * Helpers
  */
  
  // Total comment count
  public function comment_count()
  {
    $this->init();

    $folder      = $this->fetch_param('folder', $this->comment_folder); // defaults to null
    if ($folder != $this->comment_folder) {
      $this->get_comment_path();
      $folder = $this->comment_folder; // resolve new folder path
    }
    
    if (!$this->total_items) {
      $this->total_items = Statamic::get_content_count($this->comment_folder, false, true, null, null, null);
    }
    return $this->total_items;
  }
  
  /**
  * protected Methods
  */
  
  protected function get_comment_path($folder=false)
  {
    if (!$folder) {
      $folder = $this->app->request()->getResourceUri();
    }
    $this->comment_folder = '_comments'.Statamic_helper::resolve_path($folder);
    $this->raw_comment_folder = '_content/'.$this->comment_folder;
    $this->comment_file = $this->raw_comment_folder . '/' . date('Y-m-d').'_'.time().'.md';
  }
  
  // Check if logged in
  protected function logged_in() {
    return Statamic_Auth::is_logged_in();
  }

  protected function user_datas() {
    if (!$this->user_datas) {
      $this->user_datas = Statamic_Auth::get_current_user();  // retrieve user datas
    }
    return $this->user_datas;
  }
    
  /**
   * Print the username when logged in
   */
  protected function username() {
    $username = '';
    if ($current_user = $this->user_datas()) {
      $username = $current_user->get_name();  // username is used by default
      
      // if there's a last name, great !
      if ($last_name = $current_user->get_last_name()) {
        $username = $last_name;
      }
      
      // ok there's a first name, go for it !
      if ($first_name = $current_user->get_first_name()) {
        $username .= ' '.$first_name;
      }
    }
    return $username;
  }
  
  // User email
  protected function email() {
    if ($current_user = $this->user_datas()) {
      return $this->app->config['contact_email'];
    }
    return '';
  }
  
  // Comment page count
  protected function page_count() {
    if (!$this->total_pages) {
      //$this->config['comment_per_page'] = $this->fetch_param('limit', 10, 'is_numeric');
      $this->total_pages = (int) ceil(($this->comment_count()) / $this->config['comment_per_page']);
    }
    return $this->total_pages;
  }

  // Validate the submitted form data
  protected function validate() {
    $input = $this->form_datas;
    $required = explode('|', str_replace(array('comment',$this->config['email_field'], $this->config['username_field']), '', $this->options['required']));
    
    if ( !isset($input->content) or trim($input->content) === '') {
      $this->validation[]['error'] = 'Comment is required';
    }
    
    // Email is always required
    if ( !$this->logged_in() && ( ! isset($input->{$this->config['email_field']}) or ! filter_var($input->email, FILTER_VALIDATE_EMAIL) ) ) {
      $this->validation[]['error'] = 'Email is required';
    }
    
    // Username is always required
    if ( !$this->logged_in() && ( ! isset($input->{$this->config['username_field']}) ) ) {
      $this->validation[]['error'] = 'Username is required';
    }
    
    // Honeypot !
    if ($this->options['honeypot'] && $input->wearetherobot != '' ) {
     	 $this->validation[]['error'] = 'Hello robot !';
    }

    foreach ($required as $key => $value) {
      if ($value != '' and $input->$value == '') {
        $this->validation[]['error'] = ucfirst($value).' is required';
      }
    }

    return empty($this->validation) ? true : false;
  }

  // Save the comment
  protected function save() {
    $file_content = "";
    $file_content .= "---\n";

    foreach($this->form_datas as $key=>$data) {
      if ($key != 'content' && $key != 'wearetherobot') {
        $file_content .= "{$key}: {$data}\n";
      }
    }

    $file_content .= "---\n";
    $file_content .= $this->form_datas->content;
    $file_content .= "\n";
    
    if (!file_exists($this->raw_comment_folder)) {
      mkdir($this->raw_comment_folder, 0777, true);
    }
    
    return file_put_contents($this->comment_file, $file_content);
  }
}