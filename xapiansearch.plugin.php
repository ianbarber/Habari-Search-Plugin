<?php

/* The Xapian PHP bindings need to be in the path, and the extension loaded */
include_once "xapian.php"; 

// TODO: is there a better way of generating a search link?
// TODO: Add recommendation function that returns a list of similar IDs.
// TODO: Test on PHPIR code base
// TODO: Put onto live PHPIR
// TODO: Github release and PHPIR post
// TODO: Email to Habari list with functionality and example
// TODO: Find out how current pagination is done, and try to hook to replace with get matches estimated

/**
 * Xapian based search plugin for Habari.
 * 
 * @todo Add forms UI based options page in order to set file location etc. 
 * @todo Better support for pagination count system
 * @todo Split Xapian specific stuff out, and allow for multiple backends
 * @todo Currently only supports English stemming - pick up from locale
 * @todo Could do with having the index file location be configurable
 * @todo Support for remote backends so Xapian can be elsewhere
 * @todo Handle error from opening database
 * @todo Allow filtering based on tags
 * @todo Spelling correction integration, make available to theme
 * @todo Sorting by other than relevance (date?)
 * @todo Test result order on search results per page 
 * 
 * @link http://xapian.org
 */
class XapianSearch extends Plugin
{
	/* 
		Xapian's field values need to be numeric,
		so we define some constants to help them 
		be a bit more readable.
	*/
	const XAPIAN_FIELD_URL = 0;
	const XAPIAN_FIELD_TITLE = 1;
	const XAPIAN_FIELD_PUBDATE = 2;
	const XAPIAN_FIELD_CONTENTTYPE = 3;
	const XAPIAN_FIELD_USERID = 4;
	const XAPIAN_FIELD_ID = 5;
	
	/* Std prefix from Xapian docs */
	const XAPIAN_PREFIX_UID = 'Q';
	
	/* 
		Used to know whether we should overwrite
	 	an existing database.
	*/
	const INIT_DB = 1;
	
	/**
	 * The path to the index file
	 */
	private $_indexPath; 
	
	/**
	 * The handle for the Xapian DB
	 * @var XapianDatabase
	 */
	private $_database;
	
	/**
	 * The last search query performed
	 */
	private $_lastSearch;
	
	/**
	 * The spelling correction, if needed
	 */
	private $_spelling = '';
	
	/**
	 * Null the Xapian database to cause it to flush
	 */
	public function __destruct() 
	{
		$this->_database = null; // flush xap
	}
	
	/**
	 * Initialize some internal values when plugin initializes
	 */
	public function action_init()
	{
		$this->init_paths();
		if ( !is_writable( $this->_rootPath ) ) {
			Session::error('Activation failed, Xapian directory is not writeable.', 'Xapian Search');
			Plugins::deactivate_plugin( __FILE__ ); //Deactivate plugin
			Utils::redirect(); //Refresh page. 
		}
		$this->add_template('searchspelling', dirname(__FILE__) . '/searchspelling.php');
	}
	
	/**
	 * Activate the plugin, clearing the database if it exists, and reindexing
	 * all published posts.
	 */
	public function action_plugin_activation( $file ) 
	{
		// Test the lib is there
		if( !class_exists("XapianTermIterator") ) {
			Session::error('Activation failed, Xapian does not seem to be installed.', 'Xapian Search');
			Plugins::deactivate_plugin( __FILE__ );
		}
		/*
		 * Action init doesn't get called before this, so we need to init the paths again.
		 * Helpfully, if you pass the Xapian create database null you get the error 
		 * "No matching function for overloaded 'new_WritableDatabase'"
		 * rather than anything helpful!
		 */
		$this->init_paths(); 
		$this->open_writable_database(self::INIT_DB);
		$posts = Posts::get(array(	'status' => Post::status( 'published' ),
		 							'ignore_permissions' => true,
									'nolimit' => true, // techno techno techno techno
									));
		if( $posts instanceof Posts ) {
			foreach( $posts as $post ) {
				$this->index_post($post);
			}
		} else if( $posts instanceof Post ) {
			$this->index_post($posts);
		}
	}

	/**
	 * If a post is published, add it to the search index.
	 *
	 * @param Post $post the post being inserted
	 */
	public function action_post_insert_after( $post ) 
	{
		if ( Post::status( 'published' ) != $post->status ) {
			return;
		}
		
		$this->open_writable_database();
		$this->index_post($post);
	}
	
	/** 
	 * If a post is modified, update the index
	 * 
	 * @todo Better handling on published -> unpublished & unpub modify.
	 * @param Post $post the post being updated
	 */
	public function action_post_update_after( $post ) 
	{
		if ( Post::status( 'published' ) != $post->status ) {
			// this is a bit of a fudge, as a post may never have been added.
			$this->delete_post($post);
			return;
		}
		$this->open_writable_database();
		$this->index_post($post);
	}
	
	/**
	 * If a post is deleted, remove it from the index.
	 * 
	 * @param Post $post the post being deleted
	 */
	public function action_post_delete_before( $post ) 
	{
		$this->open_writable_database();
		$this->delete_post($post);
	}
	
	/**
	 * Hook in to the param array in posts to allow handling
	 * the search results
	 *
	 * @param array $paramarray the array of parameters for the Posts get
	 */
	public function filter_posts_get_paramarray( $paramarray ) 
	{
		// TODO: Remove, debug
		// var_dump($paramarray);
		if( isset( $paramarray['criteria'] ) ) {
			
			if( $paramarray['criteria'] != '' ) {
				$this->_lastSearch = $paramarray['criteria'];
				// flag that there was a criteria, but blank it.
				$paramarray['criteria'] = '';
			}
			
			$this->open_readable_database();
			$qp = new XapianQueryParser();
			$enquire = new XapianEnquire($this->_database);
			
			// TODO: Check locale!
			$stemmer = new XapianStem("english");
			$qp->set_stemmer($stemmer);
			$qp->set_database($this->_database);
			$qp->set_stemming_strategy(XapianQueryParser::STEM_SOME);
			$query = $qp->parse_query($this->_lastSearch, 
					XapianQueryParser::FLAG_SPELLING_CORRECTION);
			   
			$enquire->set_query($query);
			if( isset($paramarray['limit']) ) {
				$limit = $paramarray['limit'];
			} else {
				// magic default number from posts, probably want to change that
				$limit = Options::get('pagination') ? (int) Options::get('pagination') : 5;
				// we'll force it to match though, just in case
				$paramarray['limit'] = $limit;
			}
			
			$offset = 0;
			if( isset($paramarray['count']) ) {
				// Fudge to get pagination kicking in
				$limit = $limit * 10;
			} else if ( isset( $paramarray['page'] ) && is_numeric( $paramarray['page'] ) ) {
				// Nix the pagination on the SQL query, so it's handled in the SE instead.
				$paramarray['offset'] = '0'; 
				$offset = ( intval( $paramarray['page'] ) - 1 ) * intval( $limit );
			}

			$this->_spelling = $qp->get_corrected_query_string();
			$matches = $enquire->get_mset($offset, $limit);

			// TODO: get count from $matches->get_matches_estimated() instead of current method
			$i = $matches->begin();
			$ids = array();
			while ( !$i->equals($matches->end()) ) {
				$n = $i->get_rank() + 1;
				$ids[] = $i->get_document()->get_value(self::XAPIAN_FIELD_ID);
				$i->next();
			}
			
			if( count($ids) > 0 ) {
				$paramarray['id'] = $ids;
			} else {
				$paramarray['id'] = array(-1);
			}
		}
		return $paramarray;
	}
	
	/**
	 * Output data for the spelling correction theme template 
	 */
	public function theme_search_spelling( $theme )
	{
		$theme->spelling = $this->_spelling;  
		return $theme->fetch( 'searchspelling' );
	}
	
	/**
	 * Return a list of posts that are similar to the current post
	 */
	public function theme_similar_posts( $theme, $post, $max_recommended = 5 ) 
	{
		$guid = $this->get_uid($post);
		$posting = $this->_database->postlist_begin($guid);
		$enquire = new XapianEnquire($this->_database);
		$rset = new XapianRset();
		$rset->add_document($posting->get_docid());
		$eset = $enquire->get_eset(20, $rset);
		// TODO: Sort this out!
		$query = new XapianQuery(XapianQuery::OP_OR, $eset->begin(), $eset->end());
		$enquire->set_query($query);	
		$matches = $enquire->get_mset(0, $max_recommended);

		$ids = array();
		while ( !$i->equals($matches->end()) ) {
			$n = $i->get_rank() + 1;
			$ids[] = $i->get_document()->get_value(self::XAPIAN_FIELD_ID);
			$i->next();
		}
		
		return Posts::get(array('id' => $ids));
	}
	

	/**
	 * Initialise a writable database for updating the index
	 * 
	 * @param int flag allow setting the DB to be initialised with self::INIT_DB
	 */
	protected function open_writable_database( $flag = 0 ) 
	{
		// Open the database for update, creating a new database if necessary.
		if( isset($this->_database) ) {
			if( $this->_database instanceof XapianWritableDatabase ) {
				return;
			} else {
				$this->_database = null;
			}
		}

		if( strlen($this->_indexPath) == 0 ) {
			Session::error('Received a bad index path in the database opening', 'Xapian Search');
			return false;
		}

		if( $flag == self::INIT_DB ) {
			$this->_database = new XapianWritableDatabase($this->_indexPath, (int)Xapian::DB_CREATE_OR_OVERWRITE);
		} else {
			$this->_database = new XapianWritableDatabase($this->_indexPath, (int)Xapian::DB_CREATE_OR_OPEN);
		}
		$this->_indexer = new XapianTermGenerator();
		// enable spelling correction
		$this->_indexer->set_database($this->_database);
		$this->_indexer->set_flags(XapianTermGenerator::FLAG_SPELLING);
		
		// TODO: Check locale! 
		$stemmer = new XapianStem("english");
		$this->_indexer->set_stemmer($stemmer);	
	}
	
	/** 
	 * Open the database for reading
	 */
	protected function open_readable_database() 
	{
		if( !isset($this->_database) ) {
			if( strlen($this->_indexPath) == 0 ) {
				Session::error('Received a bad index path in the database opening', 'Xapian Search');
				return false;
			}
			
			$this->_database = new XapianDatabase($this->_indexPath);
		}
	}
	
	/**
	 * Add a post to the index. Adds more metadata than may be strictly
	 * required. 
	 * 
	 * @param Post $post the post being inserted
	 */
	protected function index_post( $post ) 
	{
		$doc = new XapianDocument();
		$tags = $post->get_tags();
		if( is_array($tags) && count($tags) ) {
			foreach( $tags as $tag => $name ) {
				$doc->add_term("XTAG" . strtolower($tag));
			}
		}
		
		$doc->set_data($post->content);
		$doc->add_value(self::XAPIAN_FIELD_URL, $post->permalink);
		$doc->add_value(self::XAPIAN_FIELD_TITLE, $post->title);
		$doc->add_value(self::XAPIAN_FIELD_USERID, $post->user_id);
		$doc->add_value(self::XAPIAN_FIELD_PUBDATE, $post->pubdate);
		$doc->add_value(self::XAPIAN_FIELD_CONTENTTYPE, $post->content_type);
		$doc->add_value(self::XAPIAN_FIELD_ID, $post->id);	
		$this->_indexer->set_document($doc);
		$this->_indexer->index_text($post->title, 10);
		$this->_indexer->index_text($post->content, 1);
		$id = $this->get_uid($post);
		$doc->add_term($id);
		return $this->_database->replace_document($id, $doc);
	}
	
	/**
	 * Remove  a post from the index
	 *
	 * @param Post $post the post being deleted
	 */
	protected function delete_post( $post ) 
	{
		$this->_database->deleteDocument($this->get_uid($post));
	}
	
	/**
	 * Prefix the UID with the xapian GUID prefix.
	 *
	 * @param Post $post the post to extract the ID from
	 */
	protected function get_uid( $post ) 
	{
		return self::XAPIAN_PREFIX_UID . $post->id;
	}
	
	/**
	 * Initialise the file paths
	 */
	protected function init_paths() 
	{
		$this->_rootPath = HABARI_PATH . '/' . Site::get_path('user', true);
		$this->_indexPath = $this->_rootPath . 'xapian.db';
	}
}
?>