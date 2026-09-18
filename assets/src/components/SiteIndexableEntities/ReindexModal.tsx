/**
 * WordPress dependencies
 */
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import type { JobStatus, SiteJobState } from './types';
import HistoryDetailsView from './HistoryDetailsView';
import ReindexModalContent from './ReindexModalContent';

interface ReindexModalProps {
	reindexing: boolean;
	siteStates: SiteJobState[];
	cancelling: boolean;
	history: JobStatus[];
	historyPage: number;
	historyTotalPages: number;
	selectedHistoryJob: JobStatus | null;
	historyDetails: SiteJobState[];
	historyDetailsLoading: boolean;
	hasFailedHistoryDetails: boolean;
	retryingHistoryJob: boolean;
	currentSiteUrl: string;
	onClose: () => void;
	onReIndex: () => void;
	onCancelJob: () => void;
	onToggleExpand: ( siteUrl: string ) => void;
	onOpenHistoryDetails: ( job: JobStatus ) => void;
	onPageChange: ( page: number ) => void;
	onHistoryDetailsBack: () => void;
	onRetryHistoryJob: () => void;
}

const getTitle = (
	selectedHistoryJob: JobStatus | null,
	reindexing: boolean
): string => {
	if ( selectedHistoryJob ) {
		return __( 'Sync Job Details', 'onesearch' );
	}
	if ( reindexing ) {
		return __( 'Indexing Progress', 'onesearch' );
	}
	return __( 'Re-index saved entities', 'onesearch' );
};

const ReindexModal = ( {
	reindexing,
	siteStates,
	cancelling,
	history,
	historyPage,
	historyTotalPages,
	selectedHistoryJob,
	historyDetails,
	historyDetailsLoading,
	hasFailedHistoryDetails,
	retryingHistoryJob,
	currentSiteUrl,
	onClose,
	onReIndex,
	onCancelJob,
	onToggleExpand,
	onOpenHistoryDetails,
	onPageChange,
	onHistoryDetailsBack,
	onRetryHistoryJob,
}: ReindexModalProps ) => (
	<Modal
		title={ getTitle( selectedHistoryJob, reindexing ) }
		onRequestClose={ onClose }
		shouldCloseOnClickOutside={ false }
		size="large"
	>
		{ selectedHistoryJob ? (
			<HistoryDetailsView
				selectedHistoryJob={ selectedHistoryJob }
				historyDetails={ historyDetails }
				historyDetailsLoading={ historyDetailsLoading }
				hasFailedHistoryDetails={ hasFailedHistoryDetails }
				retryingHistoryJob={ retryingHistoryJob }
				currentSiteUrl={ currentSiteUrl }
				onBack={ onHistoryDetailsBack }
				onRetry={ onRetryHistoryJob }
			/>
		) : (
			<ReindexModalContent
				reindexing={ reindexing }
				siteStates={ siteStates }
				cancelling={ cancelling }
				history={ history }
				historyPage={ historyPage }
				historyTotalPages={ historyTotalPages }
				onReIndex={ onReIndex }
				onCancelJob={ onCancelJob }
				onToggleExpand={ onToggleExpand }
				onOpenHistoryDetails={ onOpenHistoryDetails }
				onPageChange={ onPageChange }
			/>
		) }
	</Modal>
);

export default ReindexModal;
