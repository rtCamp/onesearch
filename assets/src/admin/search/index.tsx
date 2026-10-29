/**
 * WordPress dependencies
 */
import { Button, Modal, Snackbar } from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * External dependencies
 */
import type { NoticeType } from '@/admin/settings/page';
import SiteIndexableEntities from '@/components/SiteIndexableEntities';
import SiteSearchSettings, {
	type PostTypeOption,
} from '@/components/SiteSearchSettings';
import {
	CURRENT_SITE_URL,
	REST_NAMESPACE,
	withTrailingSlash,
} from '@/js/utils';
import type { SiteType } from '@/types/global';

interface AllPostTypesMap {
	[ siteUrl: string ]: PostTypeOption[];
}

interface IndexableEntitiesMap {
	[ siteUrl: string ]: string[];
}

interface PostTypesError {
	site_url: string;
	message: string;
}

const SETUP_TITLE_ID = 'onesearch-setup-title';

interface FetchAllPostTypesResponse {
	errors?: PostTypesError[];
	sites: {
		[ url: string ]: {
			post_types?: Array< {
				slug?: string;
				label?: string;
				restBase?: string;
			} >;
		};
	};
}

const OneSearchSettingsPage = () => {
	const sharedSites = window.OneSearchSettings.sharedSites || [];
	const hasAlgoliaCreds =
		window.OneSearchSettings.hasAlgoliaCredentials ?? false;
	const hasPrerequisites = sharedSites.length > 0 && hasAlgoliaCreds;

	const siteType: SiteType = window.OneSearchSettings.siteType || '';
	const [ notice, setNotice ] = useState< NoticeType | null >( null );
	const [ allPostTypes, setAllPostTypes ] = useState< AllPostTypesMap >( {} );
	const [ indexableEntities, setIndexableEntities ] =
		useState< IndexableEntitiesMap >( {} );
	const [ entitiesLoadFailed, setEntitiesLoadFailed ] = useState( false );
	const [ postTypeErrors, setPostTypeErrors ] = useState< PostTypesError[] >(
		[]
	);
	const [ saving, setSaving ] = useState( false );

	const fetchEntities = async () => {
		try {
			const settings = await apiFetch< {
				onesearch_indexable_entities?: {
					entities?: IndexableEntitiesMap;
				};
			} >( { path: '/wp/v2/settings' } );
			setIndexableEntities(
				settings?.onesearch_indexable_entities?.entities || {}
			);
			setEntitiesLoadFailed( false );
		} catch {
			// Saving now would overwrite the selections of every site.
			setEntitiesLoadFailed( true );
			setNotice( {
				type: 'error',
				message: __(
					'Error fetching indexable entities.',
					'onesearch'
				),
			} );
		}
	};

	useEffect( () => {
		fetchEntities();
	}, [] );

	const handleEntitiesSaved = () => {
		fetchEntities();
	};

	// Fetch all post types.
	useEffect( () => {
		const toEntitiesMap = (
			data: FetchAllPostTypesResponse
		): AllPostTypesMap => {
			const results: AllPostTypesMap = {};
			if ( data && data.sites && typeof data.sites === 'object' ) {
				Object.keys( data.sites ).forEach( ( url ) => {
					const payload = data.sites[ url ] || {};

					// Get the list of the post types.
					const list = Array.isArray( payload.post_types )
						? payload.post_types
						: [];

					// Map out post types for each site.
					results[ withTrailingSlash( url ) ] = ( list || [] ).map(
						( { slug = '', label, restBase } = {} ) => {
							const s = String( slug );
							return {
								slug: s,
								label: String( label || s ),
								restBase: String( restBase || s ),
							};
						}
					);
				} );
			}

			// Returning the final results.
			return results;
		};

		const fetchAllPostTypes = async () => {
			try {
				const data = await apiFetch< FetchAllPostTypesResponse >( {
					path: `/${ REST_NAMESPACE }/all-post-types`,
				} );
				const mapped = toEntitiesMap( data );
				setAllPostTypes( mapped );
				setPostTypeErrors(
					Array.isArray( data?.errors ) ? data.errors : []
				);
			} catch {
				setNotice( {
					type: 'error',
					message: __(
						'Error fetching post types from sites.',
						'onesearch'
					),
				} );
			}
		};

		fetchAllPostTypes();
	}, [] );

	// Get status for Notice - map our types to Notice's expected types
	const getNoticeStatus = (
		type: NoticeType[ 'type' ] | undefined
	): 'success' | 'error' | 'warning' => {
		if ( type === 'success' || type === 'error' ) {
			return type;
		}
		return 'warning';
	};

	return (
		<>
			<>
				{ !! notice?.message && (
					<Snackbar
						// A new message remounts the Snackbar, which restarts its timer.
						key={ notice.message }
						onRemove={ () => setNotice( null ) }
						className={
							getNoticeStatus( notice.type ) === 'error'
								? 'onesearch-error-notice'
								: 'onesearch-success-notice'
						}
					>
						{ notice?.message }
					</Snackbar>
				) }
			</>

			{ siteType === 'governing-site' && (
				<>
					<div className="onesearch-search-content">
						<SiteIndexableEntities
							sites={ sharedSites }
							allPostTypes={ allPostTypes }
							currentSiteUrl={ withTrailingSlash(
								CURRENT_SITE_URL
							) }
							setNotice={ setNotice }
							indexableEntities={ indexableEntities }
							onEntitiesSaved={ handleEntitiesSaved }
							loadFailed={ entitiesLoadFailed }
							postTypeErrors={ postTypeErrors }
							saving={ saving }
							setSaving={ setSaving }
						/>

						<SiteSearchSettings
							setNotice={ setNotice }
							indexableEntities={ indexableEntities }
							allPostTypes={ allPostTypes }
							isIndexableEntitiesSaving={ saving }
						/>
					</div>

					{ ! hasPrerequisites && (
						<Modal
							className="onesearch-setup-modal"
							overlayClassName="onesearch-setup-overlay"
							aria={ { labelledby: SETUP_TITLE_ID } }
							onRequestClose={ () => {} }
							shouldCloseOnEsc={ false }
							shouldCloseOnClickOutside={ false }
							size="medium"
							__experimentalHideHeader
						>
							<h2 id={ SETUP_TITLE_ID }>
								{ __( 'Setup Required', 'onesearch' ) }
							</h2>
							<p>
								{ __(
									'You need to add at least one Brand Site and configure your Algolia credentials before you can set up indices and search.',
									'onesearch'
								) }
							</p>
							<Button
								variant="primary"
								href={ window.OneSearchSettings.setupUrl }
							>
								{ __( 'Go to Settings', 'onesearch' ) }
							</Button>
						</Modal>
					) }
				</>
			) }
		</>
	);
};

// Render to Gutenberg admin page with ID: onesearch-config
const target = document.getElementById( 'onesearch-search-settings' );
if ( target ) {
	const root = createRoot( target );
	root.render( <OneSearchSettingsPage /> );
}
