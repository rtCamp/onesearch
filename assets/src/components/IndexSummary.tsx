/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import type { IndexSummaryData } from '@/js/use-index-status';

// How many failed posts to show before the rest are asked for.
const FAILED_SHOWN = 5;

/**
 * Shows how many of a site's posts are in search, and the ones that couldn't be indexed.
 *
 * @param {Object}                  props       The props.
 * @param {IndexSummaryData | null} props.index The site's index summary, or null when the site can't tell what it indexes.
 */
const IndexSummary = ( { index }: { index: IndexSummaryData | null } ) => {
	const [ showAll, setShowAll ] = useState( false );

	if ( ! index ) {
		return (
			<p className="onesearch-index-summary">
				{ __(
					"This site can't tell which content it indexes. Check its connection to the governing site.",
					'onesearch'
				) }
			</p>
		);
	}

	const failed = showAll
		? index.failed
		: index.failed.slice( 0, FAILED_SHOWN );

	return (
		<div className="onesearch-index-summary">
			<p>
				{ sprintf(
					/* translators: 1: number of items in search. 2: number of items to index. */
					__( '%1$d of %2$d items are in search.', 'onesearch' ),
					index.indexed,
					index.total
				) }
			</p>
			{ index.failed.length > 0 && (
				<>
					<p className="onesearch-index-summary-failed">
						{ sprintf(
							/* translators: %d: number of items. */
							_n(
								"%d item couldn't be indexed, so search doesn't show its latest version. Update it, or re-index, to try again.",
								"%d items couldn't be indexed, so search doesn't show their latest version. Update them, or re-index, to try again.",
								index.failed.length,
								'onesearch'
							),
							index.failed.length
						) }
					</p>
					<ul className="onesearch-index-summary-list">
						{ failed.map( ( post ) => {
							const title =
								post.title ||
								sprintf(
									/* translators: %d: post ID. */
									__( 'Item %d', 'onesearch' ),
									post.id
								);

							// A deleted post has nothing to edit.
							return (
								<li key={ post.id }>
									{ post.edit_url ? (
										<a href={ post.edit_url }>{ title }</a>
									) : (
										title
									) }{ ' ' }
									<span>{ post.error }</span>
								</li>
							);
						} ) }
					</ul>
					{ ! showAll && index.failed.length > FAILED_SHOWN && (
						<Button
							variant="link"
							onClick={ () => setShowAll( true ) }
						>
							{ sprintf(
								/* translators: %d: number of items. */
								__( 'Show all %d', 'onesearch' ),
								index.failed.length
							) }
						</Button>
					) }
				</>
			) }
		</div>
	);
};

export default IndexSummary;
