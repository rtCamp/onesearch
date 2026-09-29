/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

interface HistoryPaginationProps {
	historyPage: number;
	historyTotalPages: number;
	onPageChange: ( page: number ) => void;
}

const HistoryPagination = ( {
	historyPage,
	historyTotalPages,
	onPageChange,
}: HistoryPaginationProps ) => {
	if ( historyTotalPages <= 1 ) {
		return null;
	}

	const pages: ( number | string )[] = [];
	const delta = 2;
	const last = historyTotalPages;

	pages.push( 1 );

	const rangeStart = Math.max( 2, historyPage - delta );
	const rangeEnd = Math.min( last - 1, historyPage + delta );

	if ( rangeStart > 2 ) {
		pages.push( '…' );
	}
	for ( let i = rangeStart; i <= rangeEnd; i++ ) {
		pages.push( i );
	}
	if ( rangeEnd < last - 1 ) {
		pages.push( '…' );
	}
	if ( last > 1 ) {
		pages.push( last );
	}

	const handlePageClick = ( page: number ) => {
		if ( page === historyPage || page < 1 || page > last ) {
			return;
		}
		onPageChange( page );
	};

	return (
		<div className="onesearch-history-pagination">
			<Button
				variant="tertiary"
				size="small"
				disabled={ historyPage <= 1 }
				onClick={ () => handlePageClick( historyPage - 1 ) }
				aria-label={ __( 'Previous page', 'onesearch' ) }
			>
				‹
			</Button>
			{ pages.map( ( p, idx ) => {
				if ( typeof p === 'string' ) {
					return (
						<span
							key={ `ellipsis-${ idx }` }
							className="onesearch-history-pagination-ellipsis"
						>
							…
						</span>
					);
				}
				return (
					<Button
						key={ p }
						variant={ p === historyPage ? 'primary' : 'tertiary' }
						size="small"
						onClick={ () => handlePageClick( p ) }
						className={
							p === historyPage
								? 'onesearch-history-pagination-current'
								: ''
						}
					>
						{ p }
					</Button>
				);
			} ) }
			<Button
				variant="tertiary"
				size="small"
				disabled={ historyPage >= last }
				onClick={ () => handlePageClick( historyPage + 1 ) }
				aria-label={ __( 'Next page', 'onesearch' ) }
			>
				›
			</Button>
		</div>
	);
};

export default HistoryPagination;
