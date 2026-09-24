/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalText as Text } from '@wordpress/components';
/**
 * Internal dependencies
 */
import type { SiteJobState } from './types';
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
}

const SiteRow = ( { state }: SiteRowProps ) => (
	<div className="onesearch-job-site-row">
		<div className="onesearch-job-site-header">
			<span className="onesearch-job-site-name">
				{ state.site.site_name }
			</span>
			<Text variant="muted" className="onesearch-job-site-url">
				{ state.site.site_url }
			</Text>
			<div className="onesearch-job-site-status">
				<SiteProgress state={ state } />
			</div>
		</div>
	</div>
);

export default SiteRow;
