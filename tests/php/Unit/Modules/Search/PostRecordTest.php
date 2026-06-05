<?php
/**
 * Post record unit tests.
 *
 * @package OneSearch\Tests\Unit\Modules\Search
 */

declare(strict_types = 1);

namespace OneSearch\Tests\Unit\Modules\Search;

use OneSearch\Modules\Search\Post_Record;
use OneSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagejpeg;

/**
 * Class PostRecordTest
 */
#[CoversClass( \OneSearch\Modules\Search\Post_Record::class )]
final class PostRecordTest extends TestCase {
	/**
	 * Ensures get_index_settings returns expected keys.
	 */
	public function test_get_index_settings_returns_expected_keys(): void {
		$settings = Post_Record::get_index_settings();

		$this->assertArrayHasKey( 'distinct', $settings );
		$this->assertArrayHasKey( 'attributeForDistinct', $settings );
		$this->assertArrayHasKey( 'attributesForFaceting', $settings );
		$this->assertArrayHasKey( 'searchableAttributes', $settings );
		$this->assertArrayHasKey( 'customRanking', $settings );
	}

	/**
	 * Ensures get_index_settings is filterable.
	 */
	public function test_get_index_settings_is_filterable(): void {
		$callback = static function ( array $settings ): array {
			$settings['custom-test-setting'] = true;

			return $settings;
		};

		add_filter( 'onesearch_algolia_index_settings', $callback );

		$this->assertTrue( Post_Record::get_index_settings()['custom-test-setting'] );
	}

	/**
	 * Ensures get_allowed_statuses returns publish by default.
	 */
	public function test_get_allowed_statuses_returns_publish_by_default(): void {
		$this->assertSame( [ 'publish' ], Post_Record::get_allowed_statuses( [ 'post' ] ) );
	}

	/**
	 * Ensures get_allowed_statuses adds inherit for attachments.
	 */
	public function test_get_allowed_statuses_adds_inherit_for_attachments(): void {
		$statuses = Post_Record::get_allowed_statuses( [ 'attachment' ] );

		$this->assertContains( 'publish', $statuses );
		$this->assertContains( 'inherit', $statuses );
	}

	/**
	 * Ensures get_allowed_statuses is filterable.
	 */
	public function test_get_allowed_statuses_is_filterable(): void {
		$callback = static function (): array {
			return [ 'private' ];
		};

		add_filter( 'onesearch_indexable_post_statuses', $callback );

		$this->assertSame( [ 'private' ], Post_Record::get_allowed_statuses( [ 'post' ] ) );
	}

	/**
	 * Ensures get_indexable_posts returns an array.
	 */
	public function test_get_indexable_posts_returns_array(): void {
		$posts = Post_Record::get_indexable_posts( [ 'post' ] );

		$this->assertIsArray( $posts );
	}

	/**
	 * Tests that get_indexable_posts sets paged when batching.
	 */
	public function test_get_indexable_posts_sets_paged_when_batching(): void {
		$paged    = null;
		$callback = static function ( \WP_Query $query ) use ( &$paged ): void {
			$paged = $query->query_vars['paged'] ?? null;
		};

		add_action( 'pre_get_posts', $callback );

		Post_Record::get_indexable_posts( [ 'post' ], 3, 5 );

		remove_action( 'pre_get_posts', $callback );

		$this->assertSame( 3, $paged );
	}

	/**
	 * Ensures to_records returns an array.
	 */
	public function test_to_records_returns_array(): void {
		$post = self::factory()->post->create_and_get(
			[
				'post_title'   => 'Indexed Post',
				'post_content' => 'This is searchable content.',
				'post_status'  => 'publish',
			]
		);

		$records = ( new Post_Record() )->to_records( $post );

		$this->assertIsArray( $records );
		$this->assertNotEmpty( $records );
	}

	/**
	 * Ensures to_records contains expected keys.
	 */
	public function test_to_records_contains_expected_keys(): void {
		$post = self::factory()->post->create_and_get(
			[
				'post_title'   => 'Record Keys',
				'post_content' => 'Content for generated Algolia records.',
				'post_status'  => 'publish',
			]
		);

		$records = ( new Post_Record() )->to_records( $post );

		foreach ( $records as $record ) {
			$this->assertArrayHasKey( 'objectID', $record );
			$this->assertArrayHasKey( 'content', $record );
			$this->assertArrayHasKey( 'chunk_index', $record );
			$this->assertArrayHasKey( 'total_chunks', $record );
			$this->assertArrayHasKey( 'site_post_id', $record );
			$this->assertArrayHasKey( 'post_id', $record );
			$this->assertArrayHasKey( 'post_title', $record );
			$this->assertArrayHasKey( 'post_type', $record );
			$this->assertArrayHasKey( 'site_url', $record );
		}
	}

	/**
	 * Ensures to_records handles empty content.
	 */
	public function test_to_records_with_empty_content(): void {
		$post = self::factory()->post->create_and_get(
			[
				'post_title'   => 'Empty Content',
				'post_content' => '',
				'post_status'  => 'publish',
			]
		);

		$records = ( new Post_Record() )->to_records( $post );

		$this->assertCount( 1, $records );
		$this->assertSame( '', $records[0]['content'] );
	}

	/**
	 * Tests that split_content_into_chunks splits oversized content.
	 */
	public function test_split_content_into_chunks_splits_oversized_content_into_multiple_chunks(): void {
		$record = new Post_Record();
		$method = new \ReflectionMethod( Post_Record::class, 'split_content_into_chunks' );

		$chunks = $method->invoke( $record, str_repeat( 'Chunked content for Algolia records. ', 80 ), 120 );

		$this->assertIsArray( $chunks );
		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertFalse( str_starts_with( $chunks[0], '… ' ) );
		$this->assertStringStartsWith( '… ', $chunks[1] );
	}

	/**
	 * Returns empty array when max_size is zero.
	 */
	public function test_split_content_into_chunks_returns_empty_when_max_size_zero(): void {
		$record = new Post_Record();
		$method = new \ReflectionMethod( Post_Record::class, 'split_content_into_chunks' );

		$chunks = $method->invoke( $record, 'Some content', 0 );

		$this->assertIsArray( $chunks );
		$this->assertEmpty( $chunks );
	}

	/**
	 * Returns empty array when max_size is negative.
	 */
	public function test_split_content_into_chunks_returns_empty_when_max_size_negative(): void {
		$record = new Post_Record();
		$method = new \ReflectionMethod( Post_Record::class, 'split_content_into_chunks' );

		$chunks = $method->invoke( $record, 'Some content', -10 );

		$this->assertIsArray( $chunks );
		$this->assertEmpty( $chunks );
	}

	/**
	 * Returns a single chunk when the entire content fits within max_size.
	 */
	public function test_split_content_into_chunks_returns_single_chunk_when_content_fits(): void {
		$record = new Post_Record();
		$method = new \ReflectionMethod( Post_Record::class, 'split_content_into_chunks' );

		$content = str_repeat( 'a', 10 );
		$chunks  = $method->invoke( $record, $content, 20 );

		$this->assertCount( 1, $chunks );
		$this->assertSame( $content, $chunks[0] );
	}

	/**
	 * Trims leading/trailing whitespace before chunking.
	 */
	public function test_split_content_into_chunks_trims_content(): void {
		$record = new Post_Record();
		$method = new \ReflectionMethod( Post_Record::class, 'split_content_into_chunks' );

		$chunks = $method->invoke( $record, "  \t\n  hello  \n\t  ", 100 );

		$this->assertCount( 1, $chunks );
		$this->assertSame( 'hello', $chunks[0] );
	}

	/**
	 * Tests that to_records cleans block markup from post content.
	 */
	public function test_to_records_cleans_block_markup_from_post_content(): void {
		$post = self::factory()->post->create_and_get(
			[
				'post_title'   => 'Block Content',
				'post_content' => '<!-- wp:paragraph --><p>Hello&nbsp;world</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			]
		);

		$records = ( new Post_Record() )->to_records( $post );

		$this->assertStringContainsString( 'Hello world', $records[0]['content'] );
		$this->assertStringNotContainsString( '<!-- wp:', $records[0]['content'] );
	}

	/**
	 * Tests that to_records includes post author data.
	 */
	public function test_to_records_includes_post_author_data(): void {
		$user_id = self::factory()->user->create(
			[
				'display_name' => 'Record Author',
				'first_name'   => 'Record',
				'last_name'    => 'Author',
				'user_login'   => 'record-author',
			]
		);

		$post = self::factory()->post->create_and_get(
			[
				'post_author'  => $user_id,
				'post_title'   => 'Author Metadata',
				'post_content' => 'Author metadata should be indexed.',
				'post_status'  => 'publish',
			]
		);

		$records     = ( new Post_Record() )->to_records( $post );
		$author_data = $records[0]['post_author_data'];

		$this->assertSame( 'Record Author', $author_data['author_display_name'] );
		$this->assertSame( 'Record', $author_data['author_first_name'] );
		$this->assertSame( 'Author', $author_data['author_last_name'] );
		$this->assertSame( 'record-author', $author_data['author_login'] );
		$this->assertSame( $user_id, $author_data['author_id'] );
		$this->assertSame( get_author_posts_url( $user_id ), $author_data['author_posts_url'] );
		$this->assertSame( get_avatar_url( $user_id ) ?: '', $author_data['author_avatar'] );
	}

	/**
	 * Tests that to_records includes attachment thumbnail metadata.
	 */
	public function test_to_records_includes_attachment_thumbnail_metadata(): void {
		$attachment = $this->create_test_image_attachment();

		$records = ( new Post_Record() )->to_records( $attachment );

		$this->assertNotEmpty( $records[0]['thumbnail'] );
		$this->assertStringContainsString( 'onesearch-test-image.jpg', $records[0]['thumbnail']['url'] );
		$this->assertGreaterThan( 0, $records[0]['thumbnail']['width'] );
		$this->assertGreaterThan( 0, $records[0]['thumbnail']['height'] );
	}

	/**
	 * Creates a test image attachment.
	 *
	 * @return \WP_Post The attachment post.
	 */
	private function create_test_image_attachment(): \WP_Post {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload_dir = wp_upload_dir();
		$file_path  = trailingslashit( $upload_dir['path'] ) . 'onesearch-test-image.jpg';

		$image = imagecreatetruecolor( 120, 80 );
		imagejpeg( $image, $file_path );
		imagedestroy( $image ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.imagedestroyDeprecated

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'post_title'     => 'Attachment Metadata',
			],
			$file_path
		);

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$attachment = self::factory()->attachment->get_object_by_id( $attachment_id );

		if ( ! $attachment instanceof \WP_Post ) {
			self::fail( 'Expected factory to return a WP_Post attachment.' );
		}

		return $attachment;
	}
}
