<?php

/* The Xapian PHP bindings need to be in the path, and the extension loaded */
include_once "xapian.php";
include_once dirname(__FILE__) . "/pluginsearchinterface.php";

/**
 * An implementation of a Xapian search backend for the 
 * search plugin.
 * 
 * @todo Support for remote backends so Xapian can be elsewhere (via FormUI config)
 * @todo Add weighting for tags in index
 * @todo Handle error from opening database
 * 
 * @link http://xapian.org
 */
class XapianSearch implements PluginSearchInterface 
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
	
	/**
	 * The handle for the Xapian DB
	 * @var XapianDatabase
	 */
	private $_database;
	
	/**
	 * The current stemming locale
	 */
	private $_locale;
	
	/**
	 * The spelling correction, if needed
	 */
	private $_spelling = '';
	
	/**
	 * Map of locales to xapian stemming files
	 */
	private $_stem_map = array(
		'da' => 'danish', 
		'de' => 'german',
		'en' => 'english',
		'es' => 'spanish', 
		'fi' => 'finnish',
		'fr' => 'french',
		'hu' => 'hungarian', 
		'it' => 'italian', 
		'nl' => 'dutch', 
		'no' => 'norwegian',
		'pt' => 'portuguese',
		'ro' => 'romanian', 
		'ru' => 'russian',
		'sv' => 'swedish',
		'tr' => 'turkish', 
	);
	
	/**
	 * The default stemmer to be used if not
	 * matching locale is found. If false, 
	 * no stemming will be performed. 
	 */
	private $_default_stemmer = false;
	
	/**
	 * The path to the index file
	 */
	private $_index_path;
	
	/**
	 * The path to the index file directory
	 */
	private $_root_path;
	
	/**
	 * Create the object, requires the index location.
	 *
	 * @param string $path 
	 */
	public function __construct( $path ) 
	{
		$this->_root_path = $path;
		$this->_index_path = $this->_root_path . 
							(substr($this->_root_path, -1) == '/' ? '' : '/') .
							'xapian.db';
	}
	
	/**
	 * Null the Xapian database to cause it to flush
	 */
	public function __destruct() 
	{
		$this->_database = null; // flush
	}
	
	/**
	 * Check whether the preconditions for the plugin are installed
	 *
	 * @return boolean
	 */
	public function check_conditions() 
	{
		$ok = true;
		if( !is_writable( $this->_root_path ) ) {
			Session::error( 'Init failed, Search index directory is not writeable. Please update configuration with a writeable directiory.', 'Multi Search' );
			$ok = false;
		}
		if( !class_exists("XapianTermIterator") ) {
			Session::error( 'Init failed, Xapian extension or php file not installed.', 'Multi Search' );
			$ok = false;
		}
		
		return $ok;
	}
	
	/** 
	 * Open the database for reading
	 */
	public function open_readable_database() 
	{
		if( !isset($this->_database) ) {
			if( strlen($this->_index_path) == 0 ) {
				Session::error('Received a bad index path in the database opening', 'Xapian Search');
				return false;
			}
			
			$this->_database = new XapianDatabase( $this->_index_path );
		}
	}
	
	/**
	 * Initialise a writable database for updating the index
	 * 
	 * @param int flag allow setting the DB to be initialised with PluginSearchInterface::INIT_DB
	 */
	public function open_writable_database( $flag = 0 ) 
	{
		// Open the database for update, creating a new database if necessary.
		if( isset($this->_database) ) {
			if( $this->_database instanceof XapianWritableDatabase ) {
				return;
			} else {
				$this->_database = null;
			}
		}

		if( strlen($this->_index_path) == 0 ) {
			Session::error('Received a bad index path in the database opening', 'Xapian Search');
			return false;
		}

		// Create/Open or Create/Overwrite depending on whether the module is being init
		/*
		 * NB: Helpfully, if you pass the Xapian create database null you get the error 
		 * "No matching function for overloaded 'new_WritableDatabase'"
		 * rather than anything helpful!
		 */
		if( $flag == PluginSearchInterface::INIT_DB ) {
			$this->_database = new XapianWritableDatabase( $this->_index_path, (int)Xapian::DB_CREATE_OR_OVERWRITE );
		} else {
			$this->_database = new XapianWritableDatabase( $this->_index_path, (int)Xapian::DB_CREATE_OR_OPEN );
		}
		
		$this->_indexer = new XapianTermGenerator();
		
		// enable spelling correction
		$this->_indexer->set_database( $this->_database );
		$this->_indexer->set_flags( XapianTermGenerator::FLAG_SPELLING );
		
		// enable stemming
		if($this->get_stem_locale()) {
			// Note, there may be a problem if this is different than at search time!
			$stemmer = new XapianStem( $this->get_stem_locale() );
			$this->_indexer->set_stemmer( $stemmer );				
		}
	}
	
	/**
	 * Return a list of IDs for the given search criters
	 *
	 * @param string $criteria 
	 * @param int $limit 
	 * @param int $offset 
	 * @return array
	 */
	public function get_by_criteria( $criteria, $limit, $offset ) 
	{
		$qp = new XapianQueryParser();
		$enquire = new XapianEnquire( $this->_database );
		
		if($this->get_stem_locale()) {
			// Note, there may be a problem if this is different than at indexing time!
			$stemmer = new XapianStem( $this->get_stem_locale() );
			$qp->set_stemmer( $stemmer );	
			$qp->set_stemming_strategy( XapianQueryParser::STEM_SOME );			
		}
		$qp->set_database( $this->_database );
		$query = $qp->parse_query( $criteria, 
				XapianQueryParser::FLAG_SPELLING_CORRECTION );
		   
		$enquire->set_query( $query );
		
		$this->_spelling = $qp->get_corrected_query_string();
		$matches = $enquire->get_mset( $offset, $limit );

		// TODO: get count from $matches->get_matches_estimated() instead of current method
		$i = $matches->begin();
		$ids = array();
		while ( !$i->equals($matches->end()) ) {
			$n = $i->get_rank() + 1;
			$ids[] = $i->get_document()->get_value( self::XAPIAN_FIELD_ID );
			$i->next();
		}
		
		return $ids;
	}
	
	/**
	 * Return the spelling correction, if this exists.
	 *
	 * @return string
	 */
	public function get_corrected_query_string() 
	{
		return $this->_spelling;
	}
	
	/**
	 * Updates a post. 
	 *
	 * @param Post $post 
	 */
	public function update_post( $post ) 
	{
		return $this->index_post( $post );
	}
	
	/**
	 * Add a post to the index. Adds more metadata than may be strictly
	 * required!
	 * 
	 * @param Post $post the post being inserted
	 */
	public function index_post( $post ) 
	{
		$doc = new XapianDocument();
		
		// Store some useful stuff with the post
		$doc->set_data( $post->content);
		$doc->add_value( self::XAPIAN_FIELD_URL, $post->permalink );
		$doc->add_value( self::XAPIAN_FIELD_TITLE, $post->title );
		$doc->add_value( self::XAPIAN_FIELD_USERID, $post->user_id );
		$doc->add_value( self::XAPIAN_FIELD_PUBDATE, $post->pubdate );
		$doc->add_value( self::XAPIAN_FIELD_CONTENTTYPE, $post->content_type );
		$doc->add_value( self::XAPIAN_FIELD_ID, $post->id );
		
		// Index title and body	
		$this->_indexer->set_document( $doc );
		$this->_indexer->index_text( $post->title, 50 ); // add weight to titles
		$this->_indexer->index_text( $post->content, 1 );
		
		// Add terms
		$tags = $post->tags;
		foreach( $tags as $id => $tag ) {
			$tag = (string)$tag;
			$this->_indexer->index_text( $tag, 1, 'XTAG' ); // with index for filter
			$this->_indexer->index_text( $tag, 2 ); // without prefix for index
		}
		
		// Add uid
		$id = $this->get_uid( $post );
		$doc->add_term( $id );
		
		return $this->_database->replace_document( $id, $doc );
	}
	
	/**
	 * Remove  a post from the index
	 *
	 * @param Post $post the post being deleted
	 */
	public function delete_post( $post ) 
	{
		$this->_database->delete_document( $this->get_uid($post) );
	}
	
	/**
	 * Return a list of posts that are similar to the current post
	 */
	public function get_similar_posts( $post, $max_recommended = 5 ) 
	{
		$guid = $this->get_uid($post);
		$posting = $this->_database->postlist_begin( $guid );
		$enquire = new XapianEnquire( $this->_database );
		$rset = new XapianRset();
		$rset->add_document( $posting->get_docid() );
		$eset = $enquire->get_eset(20, $rset);
		$i = $eset->begin();
		$terms = array();
		while ( !$i->equals($eset->end()) ) {
			$terms[] = $i->get_term();
			$i->next();
		}
		$query = new XapianQuery( XapianQuery::OP_OR, $terms );
		$enquire->set_query( $query );	
		$matches = $enquire->get_mset( 0, $max_recommended+1 );

		$ids = array();
		$i = $matches->begin();
		while ( !$i->equals($matches->end()) ) {
			$n = $i->get_rank() + 1;
			if( $i->get_document()->get_value(self::XAPIAN_FIELD_ID) != $post->id ) {
				$ids[] = $i->get_document()->get_value( self::XAPIAN_FIELD_ID );
			}
			$i->next();
		}

		return $ids;
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
	 * Return the current locale based on options
	 *
	 * @return string
	 */
	protected function get_stem_locale() 
	{
		if(isset($this->_locale)) {
			return $this->_locale;
		}
		if ( Options::get('locale') ) {
			$locale = Options::get('locale');
		}
		else if ( Options::get( 'system_locale' ) ) {
			$locale = Options::get( 'system_locale' );
		} else {
			$locale = 'en-us';
		}
		$locale = substr($locale, 0, 2);
		$this->_locale = isset($this->_stem_map[$locale]) ? 
							$this->_stem_map[$locale] : 
							$this->_default_stemmer;
		return $this->_locale;
	}
}