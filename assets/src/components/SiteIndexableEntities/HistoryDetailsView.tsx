/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, __experimentalText as Text } from '@wordpress/components';
/**
 * Internal dependencies
 */
import type { JobStatus, SiteJobState } from './types';
import BatchRow from './BatchRow';
import JobStatusBadge from './JobStatusBadge';
import { getHistorySites } from './utils';

interface HistoryDetailsViewProps {
	selectedHistoryJob: JobStatus;
	historyDetails: SiteJobState[];
	historyDetailsLoading: boolean;
	hasFailedHistoryDetails: boolean;
	retryingHistoryJob: boolean;
	currentSiteUrl: string;
	onBack: () => void;
	onRetry: () => void;
}

const HistoryDetailsView = ( {
	selectedHistoryJob,
	historyDetails,
	historyDetailsLoading,
	hasFailedHistoryDetails,
	retryingHistoryJob,
	currentSiteUrl,
	onBack,
	onRetry,
}: HistoryDetailsViewProps ) => {
	const historySites = getHistorySites( selectedHistoryJob, currentSiteUrl );
	const totalBatches = historySites.reduce(
		( sum, site ) => sum + ( site.batch_count || 0 ),
		0
	);

	return (
		<div className="onesearch-history-details-view">
			<div className="onesearch-history-details-toolbar">
				<Button
					variant="tertiary"
					size="default"
					onClick={ onBack }
					className="onesearch-history-details-back"
				>
					‹ { __( 'Back', 'onesearch' ) }
				</Button>
				{ ( hasFailedHistoryDetails || retryingHistoryJob ) && (
					<Button
						variant="primary"
						size="small"
						onClick={ onRetry }
						isBusy={ retryingHistoryJob }
						disabled={ retryingHistoryJob }
					>
						{ retryingHistoryJob
							? __( 'Retrying…', 'onesearch' )
							: __( 'Retry Failed Batches', 'onesearch' ) }
					</Button>
				) }
			</div>

			<div className="onesearch-history-details-summary">
				<div>
					<span>{ __( 'Sites', 'onesearch' ) }</span>
					<strong>{ historySites.length }</strong>
				</div>
				<div>
					<span>{ __( 'Batches', 'onesearch' ) }</span>
					<strong>
						{ totalBatches ||
							selectedHistoryJob.children_total ||
							selectedHistoryJob.progress_total }
					</strong>
				</div>
				<div>
					<span>{ __( 'Status', 'onesearch' ) }</span>
					<JobStatusBadge
						status={ selectedHistoryJob.status }
						size="small"
						type="text"
					/>
				</div>
			</div>

			{ selectedHistoryJob.error && (
				<div className="onesearch-job-error">
					{ selectedHistoryJob.error }
				</div>
			) }

			<div
				className={ `onesearch-history-details-content${
					historyDetailsLoading
						? ' onesearch-history-details-content--loading'
						: ''
				}` }
			>
				{ historyDetailsLoading && (
					<div className="onesearch-history-details-loading">
						<span className="onesearch-reindex-spinner" />
						<Text variant="muted">
							{ __( 'Loading job details…', 'onesearch' ) }
						</Text>
					</div>
				) }

				{ ! historyDetailsLoading && (
					<div className="onesearch-history-details-sites">
						{ historyDetails.map( ( state ) => (
							<div
								key={ state.site.site_url }
								className="onesearch-history-details-site"
							>
								<div className="onesearch-history-details-site-header">
									<div>
										<strong>
											{ state.site.site_name }
										</strong>
										<Text variant="muted">
											{ state.site.site_url }
										</Text>
									</div>
									<div className="onesearch-history-details-site-meta">
										<Text variant="muted">
											{ sprintf(
												/* translators: %d: batch count */
												__( '%d batches', 'onesearch' ),
												state.site.batch_count ||
													state.children.length
											) }
										</Text>
										{ state.reindexJob && (
											<JobStatusBadge
												status={
													state.reindexJob.status
												}
												size="small"
											/>
										) }
									</div>
								</div>

								{ state.children.length > 0 ? (
									<div className="onesearch-batch-list">
										{ state.children.map(
											( child, idx ) => (
												<BatchRow
													key={ child.id }
													child={ child }
													idx={ idx }
												/>
											)
										) }
									</div>
								) : (
									<Text
										variant="muted"
										className="onesearch-history-details-empty"
									>
										{ __(
											'No batch details available.',
											'onesearch'
										) }
									</Text>
								) }
							</div>
						) ) }
					</div>
				) }
			</div>
		</div>
	);
};

export default HistoryDetailsView;
