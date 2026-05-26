/**
 * WordPress dependencies
 */
import { useState, useEffect, createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Snackbar, Card, CardBody, Button } from '@wordpress/components';

/**
 * External dependencies
 */
import SiteIndexableEntities from '@/components/SiteIndexableEntities';
import SiteModal from '@/components/SiteModal';
import SiteSearchSettings, {
	type PostTypeOption,
} from '@/components/SiteSearchSettings';
import {
	API_NAMESPACE,
	NONCE,
	CURRENT_SITE_URL,
	withTrailingSlash,
} from '@/js/utils';
import type {
	BrandSite,
	defaultBrandSite,
	NoticeType,
} from '@/admin/settings/page';
import type { SiteType } from '@/types/global';

type BrandSiteFormData = typeof defaultBrandSite;

interface AllPostTypesMap {
	[ siteUrl: string ]: PostTypeOption[];
}

interface IndexableEntitiesMap {
	[ siteUrl: string ]: string[];
}

interface FetchAllPostTypesResponse {
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
	const gSharedSites = window.OneSearchSettings.sharedSites || [];
	const gAlgoliaCreds = window.OneSearchSettings.algoliaCredentials;
	const hasAlgoliaCreds = !! (
		gAlgoliaCreds?.app_id && gAlgoliaCreds?.write_key
	);
	const hasBrandSites = gSharedSites.length > 0;
	const hasPrerequisites = hasBrandSites && hasAlgoliaCreds;

	const [ siteType, setSiteType ] = useState< SiteType >( '' );
	const [ showModal, setShowModal ] = useState( false );
	const [ editingIndex, setEditingIndex ] = useState< number | null >( null );
	const [ sites, setSites ] = useState< BrandSite[] >( [] );
	const [ formData, setFormData ] = useState< BrandSiteFormData >( {
		name: '',
		url: '',
		api_key: '',
	} );
	const [ notice, setNotice ] = useState< NoticeType | null >( null );
	const [ allPostTypes, setAllPostTypes ] = useState< AllPostTypesMap >( {} );
	const [ indexableEntities, setIndexableEntities ] =
		useState< IndexableEntitiesMap >( {} );
	const [ saving, setSaving ] = useState( false );

	const fetchEntities = async () => {
		try {
			const res = await fetch( `${ API_NAMESPACE }/indexable-entities`, {
				headers: { 'X-WP-Nonce': NONCE },
			} );
			if ( ! res.ok ) {
				throw new Error( `HTTP ${ res.status }` );
			}
			const data = await res.json();
			setIndexableEntities( data.indexableEntities?.entities || {} );
		} catch {
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
		const token = NONCE;

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
				const res = await fetch( `${ API_NAMESPACE }/all-post-types`, {
					headers: {
						Accept: 'application/json',
						'X-WP-NONCE': token,
					},
				} );
				if ( ! res.ok ) {
					throw new Error( `HTTP ${ res.status }` );
				}
				const data: FetchAllPostTypesResponse = await res.json();
				const mapped = toEntitiesMap( data );
				setAllPostTypes( mapped );
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

	useEffect( () => {
		const token = NONCE;

		const fetchData = async () => {
			try {
				const [ siteTypeRes, sitesRes ] = await Promise.all( [
					fetch( `${ API_NAMESPACE }/site-type`, {
						headers: {
							'Content-Type': 'application/json',
							'X-WP-NONCE': token,
						},
					} ),
					fetch( `${ API_NAMESPACE }/shared-sites`, {
						headers: {
							'Content-Type': 'application/json',
							'X-WP-NONCE': token,
						},
					} ),
				] );

				const siteTypeData: { site_type?: SiteType } =
					await siteTypeRes.json();
				const sitesData: { shared_sites?: BrandSite[] } =
					await sitesRes.json();

				if ( siteTypeData?.site_type ) {
					setSiteType( siteTypeData.site_type );
				}
				if ( Array.isArray( sitesData?.shared_sites ) ) {
					setSites( sitesData.shared_sites );
				}
			} catch {
				setNotice( {
					type: 'error',
					message: __(
						'Error fetching site type or Brand sites.',
						'onesearch'
					),
				} );
			}
		};

		fetchData();
	}, [] );

	useEffect( () => {
		if ( siteType === 'governing-site' && sites.length > 0 ) {
			document.body.classList.remove( 'onesearch-missing-brand-sites' );
		}
	}, [ sites, siteType ] );

	const handleFormSubmit = async (): Promise< boolean > => {
		const updated: BrandSite[] =
			editingIndex !== null
				? sites.map( ( item, i ) =>
						i === editingIndex ? formData : item
				  )
				: [ ...sites, formData ];

		const token = NONCE;
		try {
			const response = await fetch( `${ API_NAMESPACE }/shared-sites`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-NONCE': token,
				},
				body: JSON.stringify( { sites_data: updated } ),
			} );
			if ( ! response.ok ) {
				/* eslint-disable-next-line no-console */
				console.error(
					'Error saving Brand site:',
					response.statusText
				);
				return false;
			}

			if ( sites.length === 0 ) {
				window.location.reload();
				return true;
			}

			setSites( updated );
			setNotice( {
				type: 'success',
				message: __( 'Brand Site saved successfully.', 'onesearch' ),
			} );

			setFormData( { name: '', url: '', api_key: '' } );
			setShowModal( false );
			setEditingIndex( null );
			window.location.reload();
			return true;
		} catch {
			setNotice( {
				type: 'error',
				message: __(
					'Error saving Brand site. Please try again later.',
					'onesearch'
				),
			} );
			return false;
		}
	};

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
				{ notice?.message?.length && (
					<Snackbar
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
					<div
						className={
							! hasPrerequisites
								? 'onesearch-setup-overlay-blur'
								: ''
						}
					>
						<SiteIndexableEntities
							sites={ sites }
							allPostTypes={ allPostTypes }
							currentSiteUrl={ withTrailingSlash(
								CURRENT_SITE_URL
							) }
							setNotice={ setNotice }
							onEntitiesSaved={ handleEntitiesSaved }
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

					{ showModal && (
						<SiteModal
							formData={ formData }
							setFormData={ setFormData }
							onSubmit={ handleFormSubmit }
							onClose={ () => {
								setShowModal( false );
								setEditingIndex( null );
								setFormData( {
									name: '',
									url: '',
									api_key: '',
								} );
							} }
							editing={ editingIndex !== null }
							sites={ sites }
							originalData={
								editingIndex !== null
									? sites[ editingIndex ]
									: undefined
							}
						/>
					) }

					{ ! hasPrerequisites && (
						<>
							<div className="onesearch-setup-overlay-backdrop" />
							<div className="onesearch-setup-overlay-dialog">
								<Card>
									<CardBody>
										<h2>
											{ __(
												'Setup Required',
												'onesearch'
											) }
										</h2>
										<p>
											{ __(
												'You need to add at least one Brand Site and configure your Algolia credentials before you can set up indices and search.',
												'onesearch'
											) }
										</p>
										<Button
											variant="primary"
											href={
												window.OneSearchSettings
													.setupUrl
											}
										>
											{ __(
												'Go to Settings',
												'onesearch'
											) }
										</Button>
									</CardBody>
								</Card>
							</div>
						</>
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
