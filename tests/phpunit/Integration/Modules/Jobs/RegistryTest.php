<?php
/**
 * Tests for Registry.
 *
 * @package OneSearch\Tests\Integration\Modules\Jobs
 */

declare( strict_types = 1 );

namespace OneSearch\Tests\Integration\Modules\Jobs;

use OneSearch\Modules\Jobs\Abstract_Job;
use OneSearch\Modules\Jobs\Registry;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the singleton job Registry.
 *
 * Each test gets an empty registry, and the plugin's own instance is put back
 * afterwards so the job types Bootstrap registered survive into later tests.
 */
#[CoversClass( Registry::class )]
class RegistryTest extends TestCase {
	/**
	 * The registry the plugin booted with.
	 */
	private ?Registry $original_registry = null;

	/**
	 * Swap in an empty Registry singleton before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_registry = Registry::instance();
		$this->set_registry_instance( null );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		$this->set_registry_instance( $this->original_registry );

		parent::tear_down();
	}

	/**
	 * Replace the Registry singleton via reflection.
	 *
	 * @param \OneSearch\Modules\Jobs\Registry|null $instance The instance to install, or null to have the next call build an empty one.
	 */
	private function set_registry_instance( ?Registry $instance ): void {
		$ref_instance = new \ReflectionProperty( Registry::class, 'instance' );
		$ref_instance->setAccessible( true );
		$ref_instance->setValue( null, $instance );
	}

	/**
	 * Test that instance returns the singleton registry object.
	 */
	public function test_instance_returns_same_object(): void {
		$a = Registry::instance();
		$b = Registry::instance();

		$this->assertSame( $a, $b );
	}

	/**
	 * Test that register adds a job type to the registry.
	 */
	public function test_register_adds_job_type(): void {
		Registry::instance()->register( 'test', TestConcreteJob::class );

		$this->assertTrue( Registry::instance()->has( 'test' ) );
	}

	/**
	 * Test that register rejects a class name that does not exist.
	 */
	public function test_register_throws_for_nonexistent_class(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'does not exist' );

		Registry::instance()->register( 'bad', 'NonExistentClass' );
	}

	/**
	 * Test that register rejects classes that do not extend Abstract_Job.
	 */
	public function test_register_throws_for_class_not_extending_abstract_job(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must extend' );

		Registry::instance()->register( 'bad', \stdClass::class );
	}

	/**
	 * Test that register overwrites an existing job name.
	 */
	public function test_register_overwrites_existing_name(): void {
		$registry = Registry::instance();
		$registry->register( 'job', TestConcreteJob::class );

		// Create another concrete class for overwriting.
		$registry->register( 'job', AnotherTestConcreteJob::class );

		$resolved = $registry->resolve( 'job' );
		$this->assertInstanceOf( AnotherTestConcreteJob::class, $resolved );
	}

	/**
	 * Test that resolve creates an instance of the registered job class.
	 */
	public function test_resolve_creates_fresh_instance(): void {
		Registry::instance()->register( 'test', TestConcreteJob::class );

		$job = Registry::instance()->resolve( 'test' );

		$this->assertInstanceOf( Abstract_Job::class, $job );
		$this->assertInstanceOf( TestConcreteJob::class, $job );
	}

	/**
	 * Test that resolve creates a new job instance each time.
	 */
	public function test_resolve_creates_new_instance_each_time(): void {
		Registry::instance()->register( 'test', TestConcreteJob::class );

		$a = Registry::instance()->resolve( 'test' );
		$b = Registry::instance()->resolve( 'test' );

		$this->assertNotSame( $a, $b );
	}

	/**
	 * Test that resolve rejects an unregistered job name.
	 */
	public function test_resolve_throws_for_unregistered_name(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'is not registered' );

		Registry::instance()->resolve( 'nonexistent' );
	}

	/**
	 * Test that has returns true for a registered job name.
	 */
	public function test_has_returns_true_for_registered_name(): void {
		Registry::instance()->register( 'sync', TestConcreteJob::class );
		$this->assertTrue( Registry::instance()->has( 'sync' ) );
	}

	/**
	 * Test that has returns false for an unregistered job name.
	 */
	public function test_has_returns_false_for_unregistered_name(): void {
		$this->assertFalse( Registry::instance()->has( 'unknown' ) );
	}

	/**
	 * Test that all returns the registered job map.
	 */
	public function test_all_returns_registered_map(): void {
		$registry = Registry::instance();
		$registry->register( 'alpha', TestConcreteJob::class );
		$registry->register( 'beta', AnotherTestConcreteJob::class );

		$all = $registry->all();
		$this->assertArrayHasKey( 'alpha', $all );
		$this->assertArrayHasKey( 'beta', $all );
		$this->assertSame( TestConcreteJob::class, $all['alpha'] );
		$this->assertSame( AnotherTestConcreteJob::class, $all['beta'] );
	}

	/**
	 * Test that names returns the registered job names.
	 */
	public function test_names_returns_registered_names(): void {
		$registry = Registry::instance();
		$registry->register( 'alpha', TestConcreteJob::class );
		$registry->register( 'beta', AnotherTestConcreteJob::class );

		$names = $registry->names();
		$this->assertSame( [ 'alpha', 'beta' ], $names );
	}

	/**
	 * Test that count returns the number of registered job types.
	 */
	public function test_count_returns_number_of_registered_types(): void {
		$registry = Registry::instance();
		$this->assertSame( 0, $registry->count() );

		$registry->register( 'alpha', TestConcreteJob::class );
		$this->assertSame( 1, $registry->count() );

		$registry->register( 'beta', AnotherTestConcreteJob::class );
		$this->assertSame( 2, $registry->count() );
	}
}

if ( ! class_exists( TestConcreteJob::class ) ) {
	/**
	 * Concrete job for registry tests.
	 */
	class TestConcreteJob extends Abstract_Job {
		/**
		 * Mark the test job as completed.
		 */
		public function handle(): void {
			$this->mark_completed();
		}

		/**
		 * Return the job type identifier.
		 */
		public static function get_type(): string {
			return 'test';
		}
	}
}

if ( ! class_exists( AnotherTestConcreteJob::class ) ) {
	/**
	 * Second concrete job for testing overwrites.
	 */
	class AnotherTestConcreteJob extends Abstract_Job {
		/**
		 * Mark the test job as completed.
		 */
		public function handle(): void {
			$this->mark_completed();
		}

		/**
		 * Return the job type identifier.
		 */
		public static function get_type(): string {
			return 'another_test';
		}
	}
}
