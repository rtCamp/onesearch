/**
 * WordPress dependencies
 */
import {
	Card,
	CardHeader,
	CardBody,
	Button,
	__experimentalText as Text,
	Modal,
	Notice,
} from '@wordpress/components';

/**
 * External dependencies
 */
import { useState, useEffect } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import MultiSelectChips from './MultiSelectChips';
import IndexSummary from './IndexSummary';
import JobStatus from './JobStatus';
import { withTrailingSlash } from '@/js/utils';
import { useIndexStatus } from '@/js/use-index-status';
import type { NoticeType } from '@/admin/settings/page';
import type { OneSearchSharedSite } from '@/types/global';
import type { PostTypeOption } from './SiteSearchSettings';

interface EntitiesMap {
	[ siteUrl: string ]: string[];
}

interface SiteIndexableEntitiesProps {
	sites: OneSearchSharedSite[];
	allPostTypes: Record< string, PostTypeOption[] >;
	currentSiteUrl: string;
	setNotice: ( notice: NoticeType | null ) => void;
	// Loaded by the parent, and reloaded after every save.
	indexableEntities: EntitiesMap;
	onEntitiesSaved?: () => void;
	// The last load failed, so saving would overwrite the other sites' selections.
	loadFailed?: boolean;
	postTypeErrors?: Array< { site_url: string; message: string } >;
	saving: boolean;
	setSaving: ( saving: boolean ) => void;
}

const SiteIndexableEntities = ( {
	sites,
	allPostTypes,
	currentSiteUrl,
	setNotice,
	indexableEntities,
	onEntitiesSaved,
	loadFailed = false,
	postTypeErrors = [],
	saving,
	setSaving,
}: SiteIndexableEntitiesProps ) => {
	const [ selectedEntities, setSelectedEntities ] = useState< EntitiesMap >(
		{}
	);
	const [ savedEntities, setSavedEntities ] = useState< EntitiesMap >( {} );
	const [ isReindexPending, setIsReindexPending ] = useState( false );
	const [ showReindexModal, setShowReindexModal ] = useState( false );
	const {
		sites: siteStatuses,
		jobs,
		start: startReindex,
		isIndexing,
	} = useIndexStatus();

	const controlsDisabled = saving || isReindexPending;
	const entitiesDisabled = controlsDisabled || loadFailed;

	const normalizeEntities = ( map: EntitiesMap = {} ): EntitiesMap => {
		const results: EntitiesMap = {};
		Object.keys( map || {} )
			.sort()
			.forEach( ( site ) => {
				const arr = Array.isArray( map[ site ] ) ? map[ site ] : [];
				const clean = Array.from( new Set( arr.map( String ) ) ).sort();
				if ( clean.length ) {
					results[ site ] = clean;
				}
			} );

		return results;
	};

	useEffect( () => {
		setSelectedEntities( indexableEntities );
		setSavedEntities( normalizeEntities( indexableEntities ) );
	}, [ indexableEntities ] );

	const handleSelectedEntitiesChange = (
		selected: string[],
		url: string
	) => {
		if ( entitiesDisabled ) {
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
			await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { onesearch_indexable_entities: { entities } },
			} );

			setSavedEntities( normalizeEntities( entities ) );
			onEntitiesSaved?.();
			// Re-index selected entities.
			await handleReIndex();
			return true;
		} catch ( error: unknown ) {
			setNotice( {
				message:
					( error as { message?: string } )?.message ||
					__( 'Unknown error.', 'onesearch' ),
				type: 'error',
			} );
		} finally {
			setSaving( false );
		}
		return false;
	};

	const handleReIndex = async (): Promise< boolean > => {
		try {
			setIsReindexPending( true );
			await startReindex();
			setNotice( {
				message: __(
					"Indexing started. Each site shows how it's going below.",
					'onesearch'
				),
				type: 'success',
			} );
			return true;
		} catch ( error: unknown ) {
			setNotice( {
				message:
					( error as { message?: string } )?.message ||
					__( 'Unknown error.', 'onesearch' ),
				type: 'error',
			} );
		} finally {
			setIsReindexPending( false );
			setShowReindexModal( false );
		}
		return false;
	};

	// A site's index summary and jobs.
	const renderSiteStatus = ( siteUrl: string ) => {
		const siteStatus = siteStatuses[ siteUrl ];
		const siteJobs = jobs.filter( ( job ) => job.site === siteUrl );

		return (
			<div className="onesearch-site-status">
				{ siteStatus &&
					( 'error' in siteStatus ? (
						<p className="onesearch-job-status-failed">
							{ sprintf(
								/* translators: %s: error message. */
								__(
									"This site's status couldn't be loaded. Check that the site is reachable. %s",
									'onesearch'
								),
								siteStatus.error
							) }
						</p>
					) : (
						<IndexSummary index={ siteStatus.index } />
					) ) }
				{ siteJobs.map( ( job ) => (
					<JobStatus key={ job.job } job={ job } />
				) ) }
			</div>
		);
	};

	const isDirty =
		JSON.stringify( normalizeEntities( selectedEntities ) ) !==
		JSON.stringify( savedEntities );

	// Convert PostTypeOption to the format expected by MultiSelectChips
	const toMultiSelectOptions = (
		options: PostTypeOption[]
	): Array< { slug: string; label: string; restBase: string } > => {
		return options.map( ( opt ) => ( {
			slug: opt.slug,
			label: opt.label ?? opt.slug,
			restBase: opt.restBase ?? opt.slug,
		} ) );
	};

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
								onClick={ () => setShowReindexModal( true ) }
								isBusy={ isReindexPending }
								className="onesearch-btn-reindex"
							>
								{ __( 'Re-index', 'onesearch' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ () =>
									handleSelectedEntitiesSave(
										selectedEntities
									)
								}
								disabled={ ! isDirty || saving || loadFailed }
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

				{ loadFailed && (
					<CardBody>
						<Notice status="error" isDismissible={ false }>
							{ __(
								'The saved entities could not be loaded, so saving is disabled to avoid overwriting them.',
								'onesearch'
							) }
						</Notice>
					</CardBody>
				) }

				<CardBody className="onesearch-entities-body">
					{ /* Governing Site */ }
					<div className="onesearch-entity-site">
						<div className="onesearch-entity-site-header">
							<h3 className="onesearch-entity-site-name">
								{ __( 'Governing Site', 'onesearch' ) }
							</h3>
							<p className="onesearch-entity-site-url">
								{ currentSiteUrl }
							</p>
							{ renderSiteStatus( currentSiteUrl ) }
						</div>
						<div className="onesearch-entity-selector">
							<MultiSelectChips
								placeholder={ __(
									'Select entities…',
									'onesearch'
								) }
								options={ toMultiSelectOptions(
									allPostTypes?.[ currentSiteUrl ] || []
								) }
								value={
									selectedEntities?.[ currentSiteUrl ] || []
								}
								onChange={ ( next: string[] ) =>
									handleSelectedEntitiesChange(
										next,
										currentSiteUrl
									)
								}
								valueField="slug"
								labelField="label"
								disabled={ entitiesDisabled }
							/>
						</div>
					</div>

					{ /* Brand Sites */ }
					{ sites?.map( ( site: OneSearchSharedSite ) => {
						const siteKey = withTrailingSlash( site.url );
						const siteError = postTypeErrors.find(
							( error ) =>
								withTrailingSlash( error.site_url ) === siteKey
						);

						return (
							<div
								key={ siteKey }
								className="onesearch-entity-site onesearch-entity-brand"
							>
								<div className="onesearch-entity-site-header">
									<h3 className="onesearch-entity-site-name">
										{ site.name }
									</h3>
									<p className="onesearch-entity-site-url">
										{ site.url }
									</p>
									{ renderSiteStatus( siteKey ) }
								</div>
								{ ! allPostTypes?.[ siteKey ] ? (
									<Text variant="muted">
										{ siteError
											? siteError.message
											: __(
													'No entities to select. Please check site configuration',
													'onesearch'
												) }
									</Text>
								) : (
									<div className="onesearch-entity-selector">
										<MultiSelectChips
											placeholder={ __(
												'Select entities…',
												'onesearch'
											) }
											options={ toMultiSelectOptions(
												allPostTypes?.[ siteKey ] || []
											) }
											value={
												selectedEntities?.[ siteKey ] ||
												[]
											}
											onChange={ ( next: string[] ) =>
												handleSelectedEntitiesChange(
													next,
													siteKey
												)
											}
											valueField="slug"
											labelField="label"
											disabled={ entitiesDisabled }
										/>
									</div>
								) }
							</div>
						);
					} ) }
				</CardBody>
			</Card>
			{ showReindexModal && (
				<Modal
					title={ __( 'Re-index saved entities', 'onesearch' ) }
					onRequestClose={ () => setShowReindexModal( false ) }
					shouldCloseOnClickOutside={ false }
					size="medium"
				>
					{ isIndexing && (
						<p>
							{ __(
								'Indexing is already running. Re-index to start it over.',
								'onesearch'
							) }
						</p>
					) }
					<p>
						{ __(
							'Re-index only indexes the entities you saved. Save your changes first to include them.',
							'onesearch'
						) }
					</p>
					<div
						style={ {
							display: 'flex',
							justifyContent: 'flex-end',
							marginTop: '24px',
						} }
					>
						<Button
							variant="secondary"
							onClick={ () => setShowReindexModal( false ) }
							disabled={ isReindexPending }
							style={ { marginRight: '8px' } }
						>
							{ __( 'Cancel', 'onesearch' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => handleReIndex() }
							isBusy={ isReindexPending }
							disabled={ isReindexPending }
						>
							{ __( 'Re-index', 'onesearch' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
};

export default SiteIndexableEntities;
