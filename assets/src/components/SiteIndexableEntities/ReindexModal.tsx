/**
 * WordPress dependencies
 */
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * Internal dependencies
 */
import type { JobStatus, SiteJobState } from './types';
import ReindexModalContent from './ReindexModalContent';

interface ReindexModalProps {
	reindexing: boolean;
	siteStates: SiteJobState[];
	cancelling: boolean;
	history: JobStatus[];
	historyPage: number;
	historyTotalPages: number;
	retryingJobId: string | null;
	onClose: () => void;
	onReIndex: () => void;
	onCancelJob: () => void;
	onRetryHistoryJob: ( job: JobStatus ) => void;
	onPageChange: ( page: number ) => void;
}

const ReindexModal = ( {
	reindexing,
	siteStates,
	cancelling,
	history,
	historyPage,
	historyTotalPages,
	retryingJobId,
	onClose,
	onReIndex,
	onCancelJob,
	onRetryHistoryJob,
	onPageChange,
}: ReindexModalProps ) => (
	<Modal
		title={
			reindexing
				? __( 'Indexing Progress', 'onesearch' )
				: __( 'Re-index saved entities', 'onesearch' )
		}
		onRequestClose={ onClose }
		shouldCloseOnClickOutside={ false }
		size="large"
	>
		<ReindexModalContent
			reindexing={ reindexing }
			siteStates={ siteStates }
			cancelling={ cancelling }
			history={ history }
			historyPage={ historyPage }
			historyTotalPages={ historyTotalPages }
			retryingJobId={ retryingJobId }
			onReIndex={ onReIndex }
			onCancelJob={ onCancelJob }
			onRetryHistoryJob={ onRetryHistoryJob }
			onPageChange={ onPageChange }
		/>
	</Modal>
);

export default ReindexModal;
