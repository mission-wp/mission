<?php
/**
 * Main plugin class that bootstraps all modules.
 *
 * @package Mission
 */

namespace Mission;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin class.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Database module instance.
	 *
	 * @var Database\DatabaseModule|null
	 */
	private ?Database\DatabaseModule $database_module = null;

	/**
	 * Admin module instance.
	 *
	 * @var Admin\AdminModule|null
	 */
	private ?Admin\AdminModule $admin_module = null;

	/**
	 * Blocks module instance.
	 *
	 * @var Blocks\BlocksModule|null
	 */
	private ?Blocks\BlocksModule $blocks_module = null;

	/**
	 * REST module instance.
	 *
	 * @var Rest\RestModule|null
	 */
	private ?Rest\RestModule $rest_module = null;

	/**
	 * Email module instance.
	 *
	 * @var Email\EmailModule|null
	 */
	private ?Email\EmailModule $email_module = null;

	/**
	 * Form post type instance.
	 *
	 * @var Forms\FormPostType|null
	 */
	private ?Forms\FormPostType $form_post_type = null;

	/**
	 * Get plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the plugin and all modules.
	 *
	 * @return void
	 */
	public function init(): void {
		// Initialize database module first (needed by other modules).
		$this->database_module = new Database\DatabaseModule();
		$this->database_module->init();

		// Initialize form post type (must be before blocks module).
		$this->form_post_type = new Forms\FormPostType();
		$this->form_post_type->init();

		// Initialize blocks module (registers custom blocks).
		$this->blocks_module = new Blocks\BlocksModule();
		$this->blocks_module->init();

		// Initialize admin module.
		$this->admin_module = new Admin\AdminModule();
		$this->admin_module->init();

		// Initialize REST module.
		$this->rest_module = new Rest\RestModule();
		$this->rest_module->init();

		// Initialize email module.
		$this->email_module = new Email\EmailModule();
		$this->email_module->init();
	}

	/**
	 * Get database module instance.
	 *
	 * @return Database\DatabaseModule|null
	 */
	public function get_database_module(): ?Database\DatabaseModule {
		return $this->database_module;
	}

	/**
	 * Get admin module instance.
	 *
	 * @return Admin\AdminModule|null
	 */
	public function get_admin_module(): ?Admin\AdminModule {
		return $this->admin_module;
	}

	/**
	 * Get blocks module instance.
	 *
	 * @return Blocks\BlocksModule|null
	 */
	public function get_blocks_module(): ?Blocks\BlocksModule {
		return $this->blocks_module;
	}

	/**
	 * Get REST module instance.
	 *
	 * @return Rest\RestModule|null
	 */
	public function get_rest_module(): ?Rest\RestModule {
		return $this->rest_module;
	}

	/**
	 * Get email module instance.
	 *
	 * @return Email\EmailModule|null
	 */
	public function get_email_module(): ?Email\EmailModule {
		return $this->email_module;
	}
}
