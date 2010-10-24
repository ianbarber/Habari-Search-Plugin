<?php

include_once dirname(__FILE__) . "/pluginsearchinterface.php";
include_once "Zend/Search/Lucene.php";

/**
 * A wrapper plugin for the Zend Framework implementation of Lucene.
 * 
 * @todo Add support for the highlighting functionality in the search snippet
 *
 * @link http://framework.zend.com/manual/en/zend.search.lucene.html
 */
class ZendSearchLucene implements PluginSearchInterface 
{
	/**
	 * The default limit for retrieving items
	 */
	const DEFAULT_LIMIT = 50;
	
	/**
	 * The Lucene index object. 
	 *
	 * @var Zend_Search_Lucene_Interface
	 */
	private $_index;
	
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
							'zsl.db';
	}
	
	/**
	 * Null the index so ZSL can clean up.
	 */
	public function __destruct() 
	{
		$this->_index = null;
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
		if( !class_exists("Zend_Search_Lucene") ) {
			Session::error( 'Init failed, Zend Framework or Zend Search Lucene not installed.', 'Multi Search' );
			$ok = false;
		}

		return $ok;
	}
	
	/**
	 * Initialise a writable database for updating the index
	 * 
	 * @param int flag allow setting the DB to be initialised with PluginSearchInterface::INIT_DB
	 */
	public function open_writable_database( $flag = 0 ) 
	{
		Zend_Search_Lucene::setResultSetLimit( 50 );
		Zend_Search_Lucene_Analysis_Analyzer::setDefault( new Zend_Search_Lucene_Analysis_Analyzer_Common_Utf8() );
		
		if( PluginSearchInterface::INIT_DB == $flag ) {
			$this->_index = Zend_Search_Lucene::create($this->_index_path);
		} else {
			$this->_index = Zend_Search_Lucene::open($this->_index_path);
		}
	}
	
	/** 
	 * Prepare the database for reading
	 */
	public function open_readable_database() {
		return $this->open_writable_database();
	}
	
	/**
	 * Return a list of IDs for the given search criteria
	 *
	 * @param string $criteria 
	 * @param int $limit 
	 * @param int $offset 
	 * @return array
	 *
	 * @todo Add caching for pagination rather than current method
	 */
	public function get_by_criteria( $criteria, $limit, $offset ) {
		$hits = $this->_index->find( strtolower( $criteria ) );
		$ids = array();
		
		$counter = 1;
		foreach( $hits as $hit ) {
			if( $offset < $counter ) {
				$ids[] = $hit->postid;
			}
			$counter++;
			if( $counter > ($limit+$offset) ) {
				break;
			}
		}
		
		return $ids;
	}
	
	/**
	 * Add a post to the index. 
	 * 
	 * @param Post $post the post being inserted
	 */
	public function index_post( $post ) 
	{
		$doc = new Zend_Search_Lucene_Document();
		
		$doc->addField( Zend_Search_Lucene_Field::Text( 'url', $post->permalink ) );
		$title = Zend_Search_Lucene_Field::Text( 'title', strtolower( $post->title ), 'utf-8' );
		$title->boost = 50;
		$doc->addField( $title );
		
		$doc->addField( Zend_Search_Lucene_Field::UnStored( 'contents', strtolower( $post->content ), 'utf-8' ) );
		
		// Add tags
		$tags = $post->tags;
		$tagstring = '';
		foreach($tags as $tag) {
			$tagstring .= $tag . ' ';
		}
		$dtag = Zend_Search_Lucene_Field::UnStored( 'tags', strtolower( $tagstring ), 'utf-8' );
		$dtag->boost = 10;
		$doc->addField( $dtag );
	
		// Add ID
		$doc->addField( Zend_Search_Lucene_Field::keyword( 'postid', $post->id ) );

		$this->_index->addDocument($doc);
	}
	
	/**
	 * Updates a post. 
	 *
	 * @param Post $post 
	 */
	public function update_post( $post ) 
	{
		$this->delete_post( $post );
		return $this->index_post( $post );
	}
	
	/**
	 * Remove  a post from the index
	 *
	 * @param Post $post the post being deleted
	 */
	public function delete_post( $post ) 
	{
		$term = new Zend_Search_Lucene_Index_Term( $post->id, 'postid' );
		$docIds  = $this->_index->termDocs( $term );
		foreach ( $docIds as $id ) {
			$this->_index->delete( $id );
		}
	}
	
	/**
	 * Return a list of posts that are similar to the current post.
	 * This is not a very good implementation, so do not expect 
	 * amazing results - the term vector is not available for a doc
	 * in ZSL, which limits how far you can go!
	 *
	 * @return array ids 
	 */
	public function get_similar_posts( $post, $max_recommended = 5 ) {
		Zend_Search_Lucene::setResultSetLimit( $max_recommended + 1 );
		
		$title = $post->title;
		$tags = $post->tags;
		$tagstring = '';
		foreach($tags as $tag) {
			$tagstring .= $tag . ' ';
		}
		
		$analyser = Zend_Search_Lucene_Analysis_Analyzer::getDefault();
		$tokens = $analyser->tokenize( strtolower( $tagstring ) . ' ' . strtolower( $title ) );
		$query = new Zend_Search_Lucene_Search_Query_MultiTerm();
		foreach( $tokens as $token ) {
			$query->addTerm( new Zend_Search_Lucene_Index_Term( $token->getTermText() ) );
		}

		$hits = $this->_index->find( $query );
		$ids = array();
		
		$counter = 0;
		foreach( $hits as $hit ) {
			if( $hit->postid != $post->id ) {
				$ids[] = $hit->postid;
				$counter++;
			}
			if( $counter == $max_recommended ) {
				break;
			}
		}
		
		return $ids;
	}
	
	/**
	 * Return the spelling correction. Not implemented by
	 * this backend.
	 *
	 * @return string
	 */
	public function get_corrected_query_string() {
		return '';
	}
}