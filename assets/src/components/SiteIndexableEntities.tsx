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
} from '@wordpress/components';

/**
 * External dependencies
 */
import { useState, useEffect, useCallback } from 'react';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import MultiSelectChips from './MultiSelectChips';
import { API_NAMESPACE, NONCE, withTrailingSlash } from '@/js/utils';
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
	onEntitiesSaved?: () => void;
	saving: boolean;
	setSaving: ( saving: boolean ) => void;
}

interface IndexableEntitiesResponse {
	indexableEntities: {
		entities: EntitiesMap;
	};
}

interface SaveEntitiesResponse {
	success: boolean;
	message?: string;
	data?: {
		status?: number;
	};
}

interface ReIndexResponse {
	success: boolean;
	message?: string;
	data?: {
		status?: number;
	};
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
	const [ reindexing, setReindexing ] = useState( false );
	const [ showReindexingModal, setShowReindexingModal ] = useState( false );

	const controlsDisabled = saving || reindexing;

	const normalizeEntities = ( map: EntitiesMap = {} ): EntitiesMap => {
		const results: EntitiesMap = {};
		Object.keys( map || {} )
			.sort()
			.forEach( ( site ) => {
				const arr = Array.isArray( map[ site ] ) ? map[ site ] : [];
				const clean = Array.from( new Set( arr.map( String ) ) ).sort();
				results[ site ] = clean;
			} );

		return results;
	};

	const isEmptySavedEntities = (): boolean => {
		if ( ! savedEntities || typeof savedEntities !== 'object' ) {
			return true;
		}

		const keys = Object.keys( savedEntities );
		if ( keys.length === 0 ) {
			return true;
		}

		return keys.every( ( key ) => {
			const value = savedEntities[ key ];
			return ! Array.isArray( value ) || value.length === 0;
		} );
	};

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
		if ( controlsDisabled ) {
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
				// Re-index selected entities.
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
			setNotice( {
				message,
				type: 'error',
			} );
		} finally {
			setSaving( false );
		}
		return false;
	};

	const handleReIndex = async (): Promise< boolean > => {
		try {
			setReindexing( true );
			// @todo use @wordpress/api-fetch everywhere internal.
			const response = await fetch( `${ API_NAMESPACE }/re-index`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE,
				},
			} );

			const data: ReIndexResponse = await response.json();
			if ( data.success ) {
				setNotice( {
					message:
						data.message ||
						__( 'Re-indexing complete.', 'onesearch' ),
					type: 'success',
				} );
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
			setNotice( {
				message,
				type: 'error',
			} );
		} finally {
			setReindexing( false );
			setShowReindexingModal( false );
		}
		return false;
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
								onClick={ () => setShowReindexingModal( true ) }
								isBusy={ reindexing }
								disabled={
									reindexing || isEmptySavedEntities()
								}
								className="onesearch-btn-reindex"
							>
								{ reindexing
									? __( 'Re-indexing…', 'onesearch' )
									: __( 'Re-index', 'onesearch' ) }
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
					{ /* Governing Site */ }
					<div className="onesearch-entity-site">
						<div className="onesearch-entity-site-header">
							<h3 className="onesearch-entity-site-name">
								{ __( 'Governing Site', 'onesearch' ) }
							</h3>
							<p className="onesearch-entity-site-url">
								{ currentSiteUrl }
							</p>
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
								disabled={ controlsDisabled }
							/>
						</div>
					</div>

					{ /* Brand Sites */ }
					{ sites?.map( ( site: OneSearchSharedSite ) => (
						<div
							key={ withTrailingSlash( site.url ) }
							className="onesearch-entity-site onesearch-entity-brand"
						>
							<div className="onesearch-entity-site-header">
								<h3 className="onesearch-entity-site-name">
									{ site.name }
								</h3>
								<p className="onesearch-entity-site-url">
									{ site.url }
								</p>
							</div>
							{ ! allPostTypes?.[ site?.url ] ? (
								<Text variant="muted">
									{ __(
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
											allPostTypes?.[ site?.url ] || []
										) }
										value={
											selectedEntities?.[ site?.url ] ||
											[]
										}
										onChange={ ( next: string[] ) =>
											handleSelectedEntitiesChange(
												next,
												site?.url
											)
										}
										valueField="slug"
										labelField="label"
										disabled={ controlsDisabled }
									/>
								</div>
							) }
						</div>
					) ) }
				</CardBody>
			</Card>
			{ showReindexingModal && (
				<Modal
					title={ __( 'Re-index saved entities', 'onesearch' ) }
					onRequestClose={ () => setShowReindexingModal( false ) }
					shouldCloseOnClickOutside={ false }
					size="medium"
				>
					<p>
						{ __(
							'Re-indexing will only index the entities you have previously saved. To re-index modified entities, please make sure you have saved them.',
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
							onClick={ () => setShowReindexingModal( false ) }
							disabled={ reindexing }
							style={ { marginRight: '8px' } }
						>
							{ __( 'Cancel', 'onesearch' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => handleReIndex() }
							isBusy={ reindexing }
							disabled={ reindexing }
						>
							{ reindexing
								? __( 'Re-indexing…', 'onesearch' )
								: __( 'Re-index', 'onesearch' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
};

export default SiteIndexableEntities;
