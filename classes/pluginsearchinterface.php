<?php 

interface PluginSearchInterface {
	/** 
	 *	Used to know whether we should overwrite
	 *	an existing database.
	 */
	const INIT_DB = 1;
	
	/**
	 * Create the object, requires the index location.
	 *
	 * @param string $path 
	 */
	public function __construct( $path );
	
	/**
	 * Check whether the preconditions for the plugin are installed
	 *
	 * @return boolean
	 */
	public function check_conditions();
	
	/**
	 * Initialise a writable database for updating the index
	 * 
	 * @param int flag allow setting the DB to be initialised with PluginSearchInterface::INIT_DB
	 */
	public function open_writable_database( $flag = 0 );
	
	/** 
	 * Prepare the database for reading
	 */
	public function open_readable_database();
	
	/**
	 * Return a list of IDs for the given search criters
	 *
	 * @param string $criteria 
	 * @param int $limit 
	 * @param int $offset 
	 * @return array
	 */
	public function get_by_criteria( $criteria, $limit, $offset );
	
	/**
	 * Return the spelling correction, if this exists.
	 *
	 * @return string
	 */
	public function get_corrected_query_string();
	
	/**
	 * Add a post to the index. 
	 * 
	 * @param Post $post the post being inserted
	 */
	public function index_post( $post );
	
	/**
	 * Remove  a post from the index
	 *
	 * @param Post $post the post being deleted
	 */
	public function delete_post( $post );
	
	/**
	 * Return a list of posts that are similar to the current post.
	 * Backends that do not implement this should just return an empty
	 * array.
	 */
	public function get_similar_posts( $post, $max_recommended = 5 );
}