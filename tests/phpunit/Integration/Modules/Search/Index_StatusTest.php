<?php
/**
 * Index_Status tests.
 *
 * @package OneSearch\Tests\Integration\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Integration\Modules\Search;

use OneSearch\Modules\Search\Index_Status;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Index_Status class.
 */
#[CoversClass( Index_Status::class )]
final class Index_StatusTest extends TestCase {
	/**
	 * The summary counts the indexable posts and the indexed ones, and lists every post that failed, including ones that left search or were deleted.
	 */
	public function test_get_summary(): void {
		$indexed = self::factory()->post->create();
		$failed  = self::factory()->post->create( [ 'post_title' => 'Too Big' ] );
		// Not indexed yet.
		self::factory()->post->create();
		$draft     = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$protected = self::factory()->post->create( [ 'post_password' => 'secret' ] );

		Index_Status::record_saved( [ $indexed, $draft ] );
		Index_Status::record_errors(
			[
				$failed    => 'The post is too big to index.',
				$protected => 'Its records could not be deleted.',
			]
		);

		$deleted = self::factory()->post->create();
		wp_delete_post( $deleted, true );
		Index_Status::record_errors( [ $deleted => 'Its records could not be deleted.' ] );

		$this->assertSame(
			[
				'total'   => 3,
				'indexed' => 1,
				'failed'  => [
					[
						'id'       => $failed,
						'title'    => 'Too Big',
						'edit_url' => admin_url( sprintf( 'post.php?post=%d&action=edit', $failed ) ),
						'error'    => 'The post is too big to index.',
					],
					[
						'id'       => $protected,
						'title'    => get_post( $protected )->post_title,
						'edit_url' => admin_url( sprintf( 'post.php?post=%d&action=edit', $protected ) ),
						'error'    => 'Its records could not be deleted.',
					],
					[
						'id'       => $deleted,
						'title'    => 'Deleted item',
						'edit_url' => '',
						'error'    => 'Its records could not be deleted.',
					],
				],
			],
			Index_Status::get_summary( [ 'post' ] )
		);
	}
}
