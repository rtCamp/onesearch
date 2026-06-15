/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalText as Text } from '@wordpress/components';
/**
 * Internal dependencies
 */
import type { SiteJobState } from './types';
import BatchRow from './BatchRow';
import JobStatusBadge from './JobStatusBadge';

interface SiteProgressProps {
	state: SiteJobState;
}

const SiteProgress = ( { state }: SiteProgressProps ) => {
	const j = state.reindexJob;
	if ( ! j ) {
		return <Text variant="muted">…</Text>;
	}
	const childTotal = state.children.length;
	if ( childTotal > 0 ) {
		const done = state.children.filter( ( c ) =>
			[ 'completed', 'failed' ].includes( c.status )
		).length;
		return (
			<span className="onesearch-job-progress-text">
				{ done } / { childTotal } { __( 'batches', 'onesearch' ) }
			</span>
		);
	}
	return <JobStatusBadge status={ j.status } />;
};

interface SiteRowProps {
	state: SiteJobState;
	onToggleExpand: ( siteUrl: string ) => void;
}

const SiteRow = ( { state, onToggleExpand }: SiteRowProps ) => {
	const hasChildren = state.children.length > 0;
	const canExpand =
		hasChildren ||
		( !! state.reindexJob &&
			state.reindexJob.status !== 'completed' &&
			state.reindexJob.status !== 'cancelled' );

	const handleKeyDown = ( e: React.KeyboardEvent ) => {
		if ( ( e.key === 'Enter' || e.key === ' ' ) && canExpand ) {
			e.preventDefault();
			onToggleExpand( state.site.site_url );
		}
	};

	return (
		<div className="onesearch-job-site-row">
			<div
				className="onesearch-job-site-header"
				onClick={ () =>
					canExpand ? onToggleExpand( state.site.site_url ) : null
				}
				onKeyDown={ handleKeyDown }
				role={ canExpand ? 'button' : undefined }
				aria-expanded={ canExpand ? state.expanded : undefined }
				tabIndex={ canExpand ? 0 : undefined }
				style={ { cursor: canExpand ? 'pointer' : 'default' } }
			>
				<span className="onesearch-job-site-name">
					{ canExpand && (
						<span className="onesearch-job-expand-icon">
							{ state.expanded ? '▾ ' : '▸ ' }
						</span>
					) }
					{ state.site.site_name }
				</span>
				<Text variant="muted" className="onesearch-job-site-url">
					{ state.site.site_url }
				</Text>
				<div className="onesearch-job-site-status">
					<SiteProgress state={ state } />
				</div>
			</div>

			{ state.expanded && hasChildren && (
				<div className="onesearch-batch-list">
					{ state.children.map( ( child, idx ) => (
						<BatchRow
							key={ child.id }
							child={ child }
							idx={ idx }
						/>
					) ) }
				</div>
			) }

			{ state.expanded &&
				! hasChildren &&
				state.reindexJob &&
				state.reindexJob.status !== 'completed' && (
					<Text variant="muted" className="onesearch-batch-list">
						{ __( 'Waiting for batch creation…', 'onesearch' ) }
					</Text>
				) }
		</div>
	);
};

export default SiteRow;
