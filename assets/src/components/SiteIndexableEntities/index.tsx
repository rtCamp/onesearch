/**
 * WordPress dependencies
 */
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import { useCallback, useEffect, useState } from 'react';
import type { NoticeType } from '@/admin/settings/page';
import { API_NAMESPACE, NONCE, withTrailingSlash } from '@/js/utils';
import type { OneSearchSharedSite } from '@/types/global';
/**
 * Internal dependencies
 */
import type { PostTypeOption } from '../SiteSearchSettings';
import EntitySiteCard from './EntitySiteCard';
import ReindexModal from './ReindexModal';
import type {
	EntitiesMap,
	IndexableEntitiesResponse,
	SaveEntitiesResponse,
} from './types';
import { normalizeEntities } from './utils';
import { useReindexJob } from './useReindexJob';

interface SiteIndexableEntitiesProps {
	sites: OneSearchSharedSite[];
	allPostTypes: Record< string, PostTypeOption[] >;
	currentSiteUrl: string;
	setNotice: ( notice: NoticeType | null ) => void;
	onEntitiesSaved?: () => void;
	saving: boolean;
	setSaving: ( saving: boolean ) => void;
}

const SiteIndexableEntities = ( {
	sites,
	allPostTypes,
	currentSiteUrl,
	setNotice,
	onEntitiesSaved,
	saving,
	setSaving,
}: SiteIndexableEntitiesProps ) => {
	const [ selectedEntities, setSelectedEntities ] = useState< EntitiesMap >(
		{}
	);
	const [ savedEntities, setSavedEntities ] = useState< EntitiesMap >( {} );

	const {
		reindexing,
		showReindexingModal,
		setShowReindexingModal,
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
		handleReIndex,
		handleCancelJob,
		handleModalClose,
		handleHistoryDetailsBack,
		handleRetryHistoryJob,
		openHistoryDetails,
		toggleExpand,
		fetchHistory,
	} = useReindexJob( { currentSiteUrl, setNotice } );

	const entitySelectorsDisabled = saving || reindexing;

	const getIndexableEntities = useCallback( async () => {
		try {
			const response = await fetch(
				`${ API_NAMESPACE }/indexable-entities`,
				{
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE,
					},
				}
			);
			const data: IndexableEntitiesResponse = await response.json();
			const incoming: EntitiesMap =
				data.indexableEntities?.entities || {};
			setSelectedEntities( incoming );
			setSavedEntities( normalizeEntities( incoming ) );
		} catch {
			setNotice( {
				type: 'error',
				message: __(
					'Error fetching indexable entities.',
					'onesearch'
				),
			} );
		}
	}, [ setNotice ] );

	useEffect( () => {
		getIndexableEntities();
	}, [ getIndexableEntities ] );

	const handleSelectedEntitiesChange = (
		selected: string[],
		url: string
	) => {
		if ( entitySelectorsDisabled ) {
			return;
		}
		setSelectedEntities( ( prev: EntitiesMap ) => ( {
			...prev,
			[ url ]: selected,
		} ) );
	};

	const handleSelectedEntitiesSave = async (
		entities: EntitiesMap
	): Promise< boolean > => {
		try {
			setSaving( true );
			const response = await fetch(
				`${ API_NAMESPACE }/indexable-entities`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE,
					},
					body: JSON.stringify( { entities } ),
				}
			);

			if ( ! response.ok ) {
				throw new Error(
					__( 'Network response was not ok.', 'onesearch' )
				);
			}

			const data: SaveEntitiesResponse = await response.json();

			if ( data.success ) {
				setSavedEntities( normalizeEntities( entities ) );
				onEntitiesSaved?.();
				await handleReIndex();
				return true;
			} else if ( data.data?.status === 500 ) {
				setNotice( {
					message: __( 'Internal server error.', 'onesearch' ),
					type: 'error',
				} );
			} else {
				setNotice( {
					message:
						data.message || __( 'Unknown error.', 'onesearch' ),
					type: 'error',
				} );
			}
		} catch ( error: unknown ) {
			const message =
				error instanceof Error ? error.message : String( error );
			setNotice( { message, type: 'error' } );
		} finally {
			setSaving( false );
		}
		return false;
	};

	const isDirty =
		JSON.stringify( normalizeEntities( selectedEntities ) ) !==
		JSON.stringify( savedEntities );

	return (
		<>
			<Card className="onesearch-entities-card">
				<CardHeader className="onesearch-entities-card-group">
					<h2 className="onesearch-title">
						{ __( 'Select Entities to Index', 'onesearch' ) }
					</h2>
					<div className="onesearch-entities-inner-card">
						<div className="onesearch-entities-controls">
							<Button
								variant="secondary"
								onClick={ () => {
									setShowReindexingModal( ( prev ) => {
										if ( ! prev ) {
											fetchHistory( 1 );
										}
										return ! prev;
									} );
								} }
								className="onesearch-btn-reindex"
							>
								{ reindexing && (
									<span className="onesearch-btn-spinner" />
								) }
								{ __( 'Re-index', 'onesearch' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ () =>
									handleSelectedEntitiesSave(
										selectedEntities
									)
								}
								disabled={ ! isDirty || saving }
								isBusy={ saving }
								className="onesearch-btn-save-entities"
							>
								{ saving
									? __( 'Saving…', 'onesearch' )
									: __( 'Save Changes', 'onesearch' ) }
							</Button>
						</div>
						<p className="onesearch-entities-info">
							{ __(
								'Saving changes will automatically re-index the data.',
								'onesearch'
							) }
						</p>
					</div>
				</CardHeader>

				<CardBody className="onesearch-entities-body">
					<EntitySiteCard
						siteName={ __( 'Governing Site', 'onesearch' ) }
						siteUrl={ currentSiteUrl }
						options={ allPostTypes?.[ currentSiteUrl ] || [] }
						selectedValues={
							selectedEntities?.[ currentSiteUrl ] || []
						}
						disabled={ entitySelectorsDisabled }
						onChange={ ( next: string[] ) =>
							handleSelectedEntitiesChange( next, currentSiteUrl )
						}
					/>
					{ sites?.map( ( site: OneSearchSharedSite ) => (
						<EntitySiteCard
							key={ withTrailingSlash( site.url ) }
							siteName={ site.name }
							siteUrl={ site.url }
							options={ allPostTypes?.[ site?.url ] }
							selectedValues={
								selectedEntities?.[ site?.url ] || []
							}
							disabled={ entitySelectorsDisabled }
							isBrand
							onChange={ ( next: string[] ) =>
								handleSelectedEntitiesChange( next, site?.url )
							}
						/>
					) ) }
				</CardBody>
			</Card>

			{ showReindexingModal && (
				<ReindexModal
					reindexing={ reindexing }
					siteStates={ siteStates }
					cancelling={ cancelling }
					history={ history }
					historyPage={ historyPage }
					historyTotalPages={ historyTotalPages }
					selectedHistoryJob={ selectedHistoryJob }
					historyDetails={ historyDetails }
					historyDetailsLoading={ historyDetailsLoading }
					hasFailedHistoryDetails={ hasFailedHistoryDetails }
					retryingHistoryJob={ retryingHistoryJob }
					currentSiteUrl={ currentSiteUrl }
					onClose={ handleModalClose }
					onReIndex={ () => void handleReIndex() }
					onCancelJob={ () => void handleCancelJob() }
					onToggleExpand={ toggleExpand }
					onOpenHistoryDetails={ ( job ) =>
						void openHistoryDetails( job )
					}
					onPageChange={ fetchHistory }
					onHistoryDetailsBack={ handleHistoryDetailsBack }
					onRetryHistoryJob={ () => void handleRetryHistoryJob() }
				/>
			) }
		</>
	);
};

export default SiteIndexableEntities;
