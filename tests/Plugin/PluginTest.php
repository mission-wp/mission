<?php
/**
 * Tests for the Plugin class.
 *
 * @package Mission
 */

namespace Mission\Tests\Plugin;

use Mission\Plugin;
use Mission\Database\DatabaseModule;
use Mission\Admin\AdminModule;
use Mission\Blocks\BlocksModule;
use Mission\Rest\RestModule;
use Mission\Email\EmailModule;
use WP_UnitTestCase;

/**
 * Plugin test class.
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * Test that instance() returns the same instance (singleton).
	 */
	public function test_singleton_returns_same_instance(): void {
		$instance1 = Plugin::instance();
		$instance2 = Plugin::instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test that instance() returns a Plugin type.
	 */
	public function test_instance_returns_plugin_type(): void {
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
	}

	/**
	 * Test that all module getters return initialized instances after init.
	 */
	public function test_module_getters_return_instances_after_init(): void {
		// Plugin is already initialized via bootstrap, so modules should exist.
		$plugin = Plugin::instance();

		$this->assertInstanceOf( DatabaseModule::class, $plugin->get_database_module() );
		$this->assertInstanceOf( AdminModule::class, $plugin->get_admin_module() );
		$this->assertInstanceOf( BlocksModule::class, $plugin->get_blocks_module() );
		$this->assertInstanceOf( RestModule::class, $plugin->get_rest_module() );
		$this->assertInstanceOf( EmailModule::class, $plugin->get_email_module() );
	}

	/**
	 * Test that module getters return null before init (via reflection).
	 */
	public function test_module_getters_return_null_before_init(): void {
		// Create a fresh Plugin instance via reflection to bypass singleton.
		$reflection  = new \ReflectionClass( Plugin::class );
		$constructor = $reflection->getConstructor();

		// Plugin constructor is private, make it accessible.
		if ( $constructor ) {
			$constructor->setAccessible( true );
		}

		$fresh_plugin = $reflection->newInstanceWithoutConstructor();

		$this->assertNull( $fresh_plugin->get_database_module() );
		$this->assertNull( $fresh_plugin->get_admin_module() );
		$this->assertNull( $fresh_plugin->get_blocks_module() );
		$this->assertNull( $fresh_plugin->get_rest_module() );
		$this->assertNull( $fresh_plugin->get_email_module() );
	}
}
