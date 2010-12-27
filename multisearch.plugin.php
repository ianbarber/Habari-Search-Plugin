<?php
include_once dirname( __FILE__ ) . "/classes/pluginsearchinterface.php";

/**
 * Extensible, but currently Xapian based, search plugin for Habari.
 * 
 * @todo Better support for pagination count system
 * @todo Allow filtering based on tags
 * @todo Sorting by other than relevance (date?)
 * @todo Caching on get_similar_posts
 * @todo Index comments as a lower weighted set of terms on their posts
 * @todo Handle ACL system
 * @todo Configure engine before attempting to build directory, so to avoid errors when directory is non-writeable
 * 
 */
class MultiSearch extends Plugin
{
	/**
	 * Constant for the option value for the index location
	 */
	const PATH_OPTION = 'multisearch__search_db_path';
	
	/**
	 * Constant for th option value for the chosen backend
	 */
	const ENGINE_OPTION = 'multisearch__chosen_engine';
	
	/**
	 * The path to the index file
	 */
	private $_root_path; 
	
	/**
	 * The last search query performed
	 */
	private $_last_search;
	
	/**
	 * Indicate whether the plugin is properly initialised
	 */
	private $_enabled;
	
	/**
	 * The backend used for searching
	 *
	 * @var PluginSearchInterface
	 */
	private $_backend = false;
	
	/**
	 * List of id => status maps for post updates.
	 *
	 * @var array
	 */
	private $_prior_status = array();
	
	/**
	 * The list of search engines that can be supported
	 *
	 * @var array
	 */
	private $_available_engines = array(
		'xapian' => 'Xapian',
		'zend_search_lucene' => 'Zend Search Lucene'
	);
	
	/**
	 * The classes and files for available engines.
	 *
	 * @package default
	 * @var array
	 */
	private $_engine_classes = array(
		'xapian' => array( 'XapianSearch', 'xapiansearch.php' ),
		'zend_search_lucene' => array( 'ZendSearchLucene', 'zendsearchlucene.php' ),
	);
	
	/**
	 * Null the backend to cause it to flush
	 */
	public function __destruct() 
	{
		$this->_backend = null;
	}
	
	/**
	 * Initialize some internal values when plugin initializes
	 */
	public function action_init()
	{
		$this->init_backend();
		if ( !$this->_backend || !$this->_backend->check_conditions() ) {
			$this->_enabled = false;
			//Utils::redirect(); //Refresh page. 
			return;
		}
		$this->add_template( 'searchspelling', dirname( __FILE__ ) . '/searchspelling.php' );
		$this->add_template( 'searchsimilar', dirname( __FILE__ ) . '/searchsimilar.php' );
		$this->_enabled = true;
	}
	
	/**
	 * Force a backend - mainly for testing. 
	 *
	 * @param PluginSearchInterface $backend 
	 */
	public function force_backend( $backend ) 
	{
		$this->_backend = $backend;
		$this->_enabled = true;
	}
	
	/**
	 * Deactivate the plugin and remove the options
	 *
	 * @param string $file 
	 */
	public function action_plugin_deactivation( $file )
	{
		Options::delete( self::ENGINE_OPTION );
	}
	
	/**
	* Add actions to the plugin page for this plugin
	* The authorization should probably be done per-user.
	*
	* @param array $actions An array of actions that apply to this plugin
	* @param string $plugin_id The string id of a plugin, generated by the system
	* @return array The array of actions to attach to the specified $plugin_id
	*/
	public function filter_plugin_config( $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ){
			if( strlen( Options::get(self::ENGINE_OPTION) ) > 0 ) {
				$actions[] = _t( 'Configure' );
			}
			$actions[] = _t( 'Choose Engine' );
		}

		return $actions;
	}
	
	/**
	* Respond to the user selecting an action on the plugin page
	*
	* @param string $plugin_id The string id of the acted-upon plugin
	* @param string $action The action string supplied via the filter_plugin_config hook
	* @todo This needs to know about remote backends as well as file paths
	*/
	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ){
			$action = strlen( Options::get(self::ENGINE_OPTION) ) > 0 ? $action : 'Choose Engine' ;
			switch ( $action ){
				case 'Configure' :
					$ui = new FormUI( strtolower( get_class( $this ) ) );
					$ui->append( 'text', 'index_path', 'option:' . self::PATH_OPTION, _t('The location where the search engine will create the search database.'));
					$ui->append( 'submit', 'save', _t( 'Save' ) );
					$ui->set_option( 'success_message', _t('Options saved') );
					$ui->on_success( array( $this, 'updated_config' ) );
					$ui->out();
					break;
				case 'Choose Engine' :
					$ui = new FormUI( strtolower( get_class( $this ) ) );
					$options_array = $this->_available_engines;
					$ui->append( 'select','engine', 'option:' . self::ENGINE_OPTION, _t('Which search engine would you like to use?'), $options_array );
					$ui->append( 'submit', 'save', _t( 'Save' ) );
					$ui->set_option( 'success_message', _t('Options saved') );
					$ui->on_success( array( $this, 'chosen_engine' ) );
					$ui->out();
					break;
			}
		}
	}
	
	/**
	 * Callback from config form submit. Store the details if the location is writeable.
	 *
	 * @param FormUI $ui 
	 * @return string|bool
	 */
	public function updated_config( $ui ) 
	{
		$this->init_backend();
		if( !$this->_backend || !$this->_backend->check_conditions() ) {
			$ui->set_option( 'success_message', _t('The engine is not configured correctly.') );
		}
		else {
			$ui->save();
			$this->reindex_all();
			return  '<p>' . _t('Search database updated.') . '</p>';
		}
		
		return false;
	}
	
	/**
	 * Callback function from choosing the engine. 
	 *
	 * @param FormUI $ui 
	 * @return string|bool
	 */
	public function chosen_engine( $ui ) 
	{
		$this->load_backend( $ui->engine->value );
		if( !$this->_backend->check_conditions() ) {
			$ui->set_option( 'success_message', _t('The engine could not be set up, please check the requirements.') );
		}
		else {
			$ui->save();
			return  '<p>' . _t('Engine saved - please press close and then select Configure in order to check the details and activate the engine.') . '</p>';
		}
		
		return false;
	}

	/**
	 * If a post is published, add it to the search index.
	 *
	 * @param Post $post the post being inserted
	 */
	public function action_post_insert_after( $post ) 
	{
		if ( !$this->_enabled || Post::status( 'published' ) != $post->status ) {
			return;
		}
		
		$this->_backend->open_writable_database();
		$this->_backend->index_post( $post );
	}
	
	/**
	 * If a post is modified, track the status in case we 
	 * need to delete it from the search index. 
	 *
	 * @param string $post 
	 * @return void
	 */
	public function action_post_update_before( $post ) 
	{
		if( !$this->_enabled ) { 
			return; 
		}
		
		$this->_prior_status[$post->id] = $post->status;
	}
	
	/** 
	 * If a post is modified, update the index
	 * 
	 * @todo Better handling on published -> unpublished & unpub modify.
	 * @param Post $post the post being updated
	 */
	public function action_post_update_after( $post ) 
	{
		if( !$this->_enabled ) { 
			return; 
		}
	
		$this->_backend->open_writable_database();
		if ( Post::status( 'published' ) != $post->status && $this->_prior_status[$post->id] == Post::status( 'published' ) ) {
			$this->_backend->delete_post( $post );
			return;
		}
		$this->_backend->update_post( $post );
	}
	
	/**
	 * If a post is deleted, remove it from the index.
	 * 
	 * @param Post $post the post being deleted
	 */
	public function action_post_delete_before( $post ) 
	{
		if( !$this->_enabled ) { 
			return; 
		}
		
		$this->_backend->open_writable_database();
		$this->_backend->delete_post( $post );
	}
	
	/**
	 * Hook in to the param array in posts to allow handling
	 * the search results
	 *
	 * @param array $paramarray the array of parameters for the Posts get
	 */
	public function filter_posts_get_paramarray( $paramarray ) 
	{
		if( $this->_enabled && isset( $paramarray['criteria'] ) ) {
			
			if( $paramarray['criteria'] != '' ) {
				$this->_last_search = $paramarray['criteria'];
				// flag that there was a criteria, but blank it.
				$paramarray['criteria'] = '';
			}
			
			if( !isset( $paramarray['limit'] ) ) {
				// magic default number from posts, probably want to change that
				$paramarray['limit'] = Options::get('pagination') ? (int) Options::get('pagination') : 5;
			}
			$limit = $paramarray['limit'];
			
			$offset = 0;
			if( isset($paramarray['count']) ) {
				// Fudge to get pagination kicking in
				$limit = $limit * 10;
			} else if ( isset( $paramarray['page'] ) && is_numeric( $paramarray['page'] ) ) {
				// Nix the pagination on the SQL query, so it's handled in the SE instead.
				$paramarray['offset'] = '0'; 
				$offset = ( intval( $paramarray['page'] ) - 1 ) * intval( $limit );
			}
			
			$this->_backend->open_readable_database();
			$ids = $this->_backend->get_by_criteria($this->_last_search, $limit, $offset);
			
			
			if( count( $ids ) > 0 ) {
				$paramarray['id'] = $ids;
				$orderby = 'CASE';
				$i = 1;
				foreach($ids as $id) {
					$orderby .= " WHEN id = " . $id . " THEN " . $i++;
				}
				$orderby .= " END";
				$paramarray['orderby'] = $orderby;
			} else {
				$paramarray['id'] = array(-1);
			}
		}
		return $paramarray;
	}
	
	/**
	 * Output data for the spelling correction theme template. By default will
	 * display a 'Did you mean?' message if spelling corrections were available,
	 * and a link to the corrected search. 
	 * 
	 * Called from theme like <code><?php $theme->search_spelling(); ?></code>
	 * USes search_spelling template.
	 * 
	 * @param Theme $theme
	 */
	public function theme_search_spelling( $theme )
	{
		if( !$this->_enabled ) { 
			return; 
		}
		
		$theme->spelling = $this->_backend->get_corrected_query_string();  
		return $theme->fetch( 'searchspelling' );
	}
	
	/**
	 * Theme function for displaying similar posts to the post passed in. By default
	 * will display a list of post titles that are considered similar to the post in
	 * question. Note that the post needs to be found in the search index, so this 
	 * will only work on published items. The easiest way with unpublished would be to 
	 * index then delete the post.
	 * 
	 * Called from a theme like <code><?php $theme->similar_posts($post); ?></code>
	 * Uses search_similar template.
	 *
	 * @param Theme $theme 
	 * @param Post $post 
	 * @param int $max_recommended 
	 */
	public function theme_similar_posts( $theme, $post, $max_recommended = 5 ) 
	{
		if( !$this->_enabled ) { 
			return; 
		}
		
		if( $this->_enabled && $post instanceof Post && intval($post->id) > 0 ) {
			$ids = $this->_backend->get_similar_posts( $post, $max_recommended );
			$theme->similar =  count($ids) > 0 ? Posts::get( array('id' => $ids) ) : array();
			$theme->base_post = $post;
			return $theme->fetch( 'searchsimilar' );
		}
	}
	
	/**
	 * Reindex the database, and reinit paths. 
	 *
	 */
	protected function reindex_all() 
	{
		if( !$this->_enabled ) { 
			return; 
		}
		
		$this->_backend->open_writable_database( PluginSearchInterface::INIT_DB );
		$posts = Posts::get(array(	'status' => Post::status( 'published' ),
		 							'ignore_permissions' => true,
									'nolimit' => true, // techno techno techno techno
									));
		if( $posts instanceof Posts ) {
			foreach( $posts as $post ) {
				$this->_backend->index_post( $post );
			}
		} else if( $posts instanceof Post ) {
			$this->_backend->index_post( $posts );
		}
	}
	
	/**
	 * Initialise the file paths
	 * 
	 */
	protected function init_backend() 
	{
		$this->_root_path = Options::get( self::PATH_OPTION );
		if(!$this->_root_path) {
			// default to this directory
			$this->_root_path = HABARI_PATH . '/' . Site::get_path( 'user', true ) . '/plugins/multisearch/indexes/';
			Options::set( self::PATH_OPTION, $this->_root_path );
		}
		
		if( Options::get( self::ENGINE_OPTION ) ) {
			$this->load_backend( Options::get( self::ENGINE_OPTION ) );
		}
	}
	
	/**
	 * Load the files for the backend
	 *
	 * @param string $engine 
	 */
	protected function load_backend( $engine ) 
	{
		if( isset( $this->_engine_classes[$engine] ) ) {
			list( $class, $file ) = $this->_engine_classes[$engine];
			include_once dirname( __FILE__ ) . "/classes/" . $file;
			$this->_backend = new $class( $this->_root_path );
			return true;
		}
		return false;
	}
}
?>