<?php

require_once "phpunit_bootstrap.php";
require_once dirname(dirname(__FILE__)) . "/classes/pluginsearchinterface.php";
require_once dirname(dirname(__FILE__)) . "/multisearch.plugin.php";
require_once 'PHPUnit/Extensions/OutputTestCase.php';

/**
 * Tests for the MultiSearch for Habari. 
 *
 */
class MultiSearchTest extends PHPUnit_Extensions_OutputTestCase {
	private $_class;
	
	public function setUp() {
		$this->_class = new MultiSearch();
	}
	
	public function getMockBackend() {
		 return $this->getMock( 'PluginSearchInterface', array( 	
															'open_writable_database', 
															'open_readable_database',
															'index_post',
															'delete_post',
															'update_post',
															'__construct',
															'__destruct',
															'check_conditions',
															'get_by_criteria',
															'get_corrected_query_string',
															'get_similar_posts',
														) );
	}
	
	public function getMockTheme() {
		$themedata = new stdClass();
		$themedata->name = 'Mock';
		$themedata->version = 1;
		$themedata->theme_dir = '/';
		$themedata->template_engine = 'rawphpengine';
		return $this->getMock( 'Theme', array( 'fetch' ), array( $themedata ) );
	}
	
	/**
	 * @test
	 */
	public function getAValidListOfActions() {
		$actions = array();
		$return = $this->_class->filter_plugin_config( $actions, false );
		$this->assertSame( $actions, $return );
		$return = $this->_class->filter_plugin_config( $actions, $this->_class->plugin_id() );
		$this->assertTrue( count($return) > 0 );
	}
	
	/**
	 * @test
	 */
	public function postIsIndexedOnCreate() {
		$post = new stdClass();
		$post->status = Post::status( 'published' );
		$backend = $this->getMockBackend();
		$backend->expects( $this->once() )->method( 'open_writable_database' );
		$backend->expects( $this->once() )->method( 'index_post' )->with( $post );
		$this->_class->force_backend( $backend );
		$this->_class->action_post_insert_after( $post );
	}

	/**
	 * @test
	 */
	public function unpublishedPostIsNotIndexed() {
		$post = new stdClass();
		$post->id = 1;
		$post->status = Post::status( 'draft' );
		$backend = $this->getMockBackend();
		$backend->expects( $this->never() )->method( 'open_writable_database' );
		$backend->expects( $this->never() )->method( 'index_post' );
		$this->_class->force_backend( $backend );
		$this->_class->action_post_insert_after( $post );
	}
	
	/**
	 * @test
	 */
	public function updatedPostIsUpdated() {
		$post = new stdClass();
		$post->id = 1;
		$post->status = Post::status( 'published' );
		$backend = $this->getMockBackend();
		$backend->expects( $this->once() )->method( 'open_writable_database' );
		$backend->expects( $this->once() )->method( 'update_post' )->with( $post );
		$this->_class->force_backend( $backend );
		$this->_class->action_post_update_after( $post );
	}
	
	/**
	 * @test
	 */
	public function deletedPostIsDeleted() {
		$post = new stdClass();
		$post->id = 1;
		$backend = $this->getMockBackend();
		$backend->expects( $this->once() )->method( 'open_writable_database' );
		$backend->expects( $this->once() )->method( 'delete_post' )->with( $post );
		$this->_class->force_backend( $backend );
		$this->_class->action_post_delete_before( $post );
	}
	
	/**
	 * @test
	 */
	public function unpublishingPostRemovesFromIndex() {
		$post = new stdClass();
		$post->status = Post::status( 'published' );
		$post->id = 1;
		$backend = $this->getMockBackend();
		$backend->expects( $this->once() )->method( 'open_writable_database' );
		$backend->expects( $this->once() )->method( 'delete_post' )->with( $post );
		$backend->expects( $this->never() )->method( 'update_post' );
		$this->_class->force_backend( $backend );
		$this->_class->action_post_update_before( $post );
		$post->status = Post::status( 'draft' );
		$this->_class->action_post_update_after( $post );
	}
	
	/**
	 * @test
	 */
	public function paramarrayWithoutCriteriaIsUnmodified() {
		$paramarray = array('hello' => 'world');
		$backend = $this->getMockBackend();
		$this->_class->force_backend( $backend );
		$return = $this->_class->filter_posts_get_paramarray( $paramarray );
		$this->assertSame($return, $paramarray);
	}
	
	/**
	 * @test
	 */
	public function paramArrayWithCriteriaIsModified() {
		$paramarray = array('criteria' => 'search terms', 'limit' => 5, 'page' => 2);
		$backend = $this->getMockBackend();
		$this->_class->force_backend( $backend );
		$backend->expects( $this->once() )->method( 'open_readable_database' );
		$backend->expects( $this->once() )->method( 'get_by_criteria' )
				->with( $paramarray['criteria'],$paramarray['limit'], 5 )
				->will( $this->returnValue(array(1, 2, 3)) );
		$return = $this->_class->filter_posts_get_paramarray( $paramarray );
	}
	
	/**
	 * @test
	 */
	public function themeSpellingReturnsTheSpellingString() {
		$backend = $this->getMockBackend();
		$this->_class->force_backend( $backend );
		$correction = 'correction';
		$backend->expects( $this->once() )->method( 'get_corrected_query_string' )
										->will( $this->returnValue( $correction ) );
		$theme = $this->getMockTheme();
		$return = $this->_class->theme_search_spelling( $theme );
		$this->assertSame( $theme->spelling, $correction );
	}
	
	/**
	 * @test
	 */
	public function themeSimilarReturnsPostsFromBackend() {
		$backend = $this->getMockBackend();
		$this->_class->force_backend( $backend );
		$max = 5;
		$post = new Post();
		$post->id = 1;
		$backend->expects( $this->once() )->method( 'get_similar_posts' )
										->with( $post, $max);
		$theme = $this->getMockTheme();
		$return = $this->_class->theme_similar_posts( $theme, $post, $max );
	}
	
	/**
	 * @test
	 */
	public function choosingEngineChecksBackend() {
		$backend = $this->getMockBackend();
		$backend->expects( $this->once() )->method( 'check_conditions' )->will( $this->returnValue( true ) );
		$ui = $this->getMock('FormUI', array('set_option'), array( 'testform' ));
		$ui->append( 'hidden','engine', 'noengine', 'test' );
		$this->_class->force_backend( $backend );
		$this->_class->chosen_engine( $ui );
	}
	
	/**
	 * @test
	 */
	public function configuringEngineChecksBackend() {
		$backend = $this->getMockBackend();
		$backend->expects( $this->once() )->method( 'check_conditions' )->will( $this->returnValue( true ) );
		$backend->expects( $this->once() )->method( 'open_writable_database' )->with( PluginSearchInterface::INIT_DB );
		$ui = $this->getMock('FormUI', array('set_option'), array( 'testform' ));
		$ui->append( 'hidden','engine', 'noengine', 'test' );
		$engine = Options::get( MultiSearch::ENGINE_OPTION );
		Options::set( MultiSearch::ENGINE_OPTION, 'noengine' );
		$this->_class->force_backend( $backend );
		$this->_class->updated_config( $ui );
		Options::set( MultiSearch::ENGINE_OPTION, $engine );
	}
	
	/**
	 * @test
	 */
	public function initShouldCheckBackendConditions() {
		$backend = $this->getMockBackend();
		$backend->expects( $this->once() )->method( 'check_conditions' )->will( $this->returnValue( true ) );
		$engine = Options::get( MultiSearch::ENGINE_OPTION );
		Options::set( MultiSearch::ENGINE_OPTION, 'noengine' );
		$this->_class->force_backend( $backend );
		$this->_class->action_init( );
		Options::set( MultiSearch::ENGINE_OPTION, $engine );
	}
	
	/**
	 * @test
	 */	
	public function configureActionShouldReturnEngineListIfNotOptionSet() {
		$engine = Options::get( MultiSearch::ENGINE_OPTION );
		Options::set( MultiSearch::ENGINE_OPTION, '' );
		$this->expectOutputRegex('/xapian/');
		$this->_class->action_plugin_ui( $this->_class->plugin_id(), 'Configure' );
		Options::set( MultiSearch::ENGINE_OPTION, $engine );
	}
	
	/**
	 * @test
	 */	
	public function configureActionShouldReturnConfigureForm() {
		$engine = Options::get( MultiSearch::ENGINE_OPTION );
		Options::set( MultiSearch::ENGINE_OPTION, 'noengine' );
		$this->expectOutputRegex('/index_path/');
		$this->_class->action_plugin_ui( $this->_class->plugin_id(), 'Configure' );
		Options::set( MultiSearch::ENGINE_OPTION, $engine );
	}

	/**
	 * @test
	 */
	public function chooseEngineActionShouldReturnEngineList() {
		$this->_class->action_plugin_ui( $this->_class->plugin_id(), 'Choose Engine' );
		$this->expectOutputRegex('/xapian/');
	}
}