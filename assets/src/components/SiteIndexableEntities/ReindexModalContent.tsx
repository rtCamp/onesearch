/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
/**
 * Internal dependencies
 */
import type { JobStatus, SiteJobState } from './types';
import HistoryPagination from './HistoryPagination';
import HistoryTable from './HistoryTable';
import SiteRow from './SiteRow';

interface ReindexModalContentProps {
	reindexing: boolean;
	siteStates: SiteJobState[];
	cancelling: boolean;
	history: JobStatus[];
	historyPage: number;
	historyTotalPages: number;
	onReIndex: () => void;
	onCancelJob: () => void;
	onToggleExpand: ( siteUrl: string ) => void;
	onOpenHistoryDetails: ( job: JobStatus ) => void;
	onPageChange: ( page: number ) => void;
}

const ReindexModalContent = ( {
	reindexing,
	siteStates,
	cancelling,
	history,
	historyPage,
	historyTotalPages,
	onReIndex,
	onCancelJob,
	onToggleExpand,
	onOpenHistoryDetails,
	onPageChange,
}: ReindexModalContentProps ) => (
	<>
		{ ! reindexing && siteStates.length === 0 && (
			<>
				<p>
					{ __(
						'Re-indexing will only index the entities you have previously saved. To re-index modified entities, please make sure you have saved them.',
						'onesearch'
					) }
				</p>
				<div className="onesearch-modal-actions">
					<Button variant="primary" onClick={ onReIndex }>
						{ __( 'Re-index', 'onesearch' ) }
					</Button>
				</div>
			</>
		) }

		{ reindexing && siteStates.length === 0 && (
			<div className="onesearch-reindex-loading">
				<span className="onesearch-reindex-spinner" />
				<p className="onesearch-reindex-loading-text">
					{ __(
						'Preparing entities for re-indexing. This may take a moment.',
						'onesearch'
					) }
				</p>
			</div>
		) }

		{ siteStates.length > 0 && (
			<div className="onesearch-job-panel">
				<div className="onesearch-job-panel-header">
					<h3>
						{ __( 'Index Job', 'onesearch' ) }{ ' ' }
						<code>
							{ siteStates[ 0 ]?.reindexJob?.id?.substring(
								0,
								20
							) ||
								siteStates[ 0 ]?.site?.job_id?.substring(
									0,
									20
								) ||
								'…' }
							…
						</code>
					</h3>
					<span className="onesearch-job-panel-total">
						{ ( () => {
							const totalBatches = siteStates.reduce(
								( sum, s ) => sum + s.children.length,
								0
							);
							const totalDone = siteStates.reduce(
								( sum, s ) =>
									sum +
									s.children.filter( ( c ) =>
										[ 'completed', 'failed' ].includes(
											c.status
										)
									).length,
								0
							);
							if ( totalBatches > 0 ) {
								return sprintf(
									/* translators: 1: done batches, 2: total batches */
									__( '%1$d / %2$d batches', 'onesearch' ),
									totalDone,
									totalBatches
								);
							}
							return '';
						} )() }
					</span>
					<Button
						variant="secondary"
						size="small"
						onClick={ onCancelJob }
						isBusy={ cancelling }
						disabled={ cancelling }
						className="onesearch-btn-cancel"
					>
						{ cancelling
							? __( 'Cancelling…', 'onesearch' )
							: __( 'Cancel Job', 'onesearch' ) }
					</Button>
				</div>

				<div className="onesearch-job-panel-body">
					{ siteStates.map( ( s ) => (
						<SiteRow
							key={ s.site.site_url }
							state={ s }
							onToggleExpand={ onToggleExpand }
						/>
					) ) }
				</div>
			</div>
		) }

		<div className="onesearch-job-history">
			<h3 className="onesearch-job-history-header">
				{ __( 'Sync Job History', 'onesearch' ) }
			</h3>
			<div className="onesearch-job-panel-body">
				<HistoryTable
					history={ history }
					onOpenDetails={ onOpenHistoryDetails }
				/>
				<HistoryPagination
					historyPage={ historyPage }
					historyTotalPages={ historyTotalPages }
					onPageChange={ onPageChange }
				/>
			</div>
		</div>
	</>
);

export default ReindexModalContent;
