/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ToggleControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
import { useState, useEffect, useCallback, useRef } from 'react';

/**
 * Internal dependencies
 */
import { NONCE, withTrailingSlash } from '../js/utils';
import type { NoticeType } from '@/admin/settings/page';

/**
 * Create NONCE middleware for apiFetch
 */
apiFetch.use( apiFetch.createNonceMiddleware( NONCE ) );

export interface PostTypeOption {
	slug: string;
	label?: string;
	restBase?: string;
}

interface SiteSearchSetting {
	algolia_enabled: boolean;
	searchable_sites: string[];
}

interface SiteInfo {
	name: string;
	url: string;
	isGoverning: boolean;
}

interface LocalNoticeType {
	type: 'success' | 'error' | 'warning';
	message: string;
}

const SiteSearchSettings = ( {
	indexableEntities,
	setNotice,
	allPostTypes,
	isIndexableEntitiesSaving,
}: {
	indexableEntities: Record< string, string[] >;
	setNotice: ( notice: NoticeType | null ) => void;
	allPostTypes: Record< string, PostTypeOption[] >;
	isIndexableEntitiesSaving: boolean;
} ) => {
	const [ searchSettings, setSearchSettings ] = useState<
		Record< string, SiteSearchSetting >
	>( {} );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ localNotice, setLocalNotice ] = useState< LocalNoticeType | null >(
		null
	);
	const [ reloadKey, setReloadKey ] = useState( 0 );
	const prevIndexableEntitiesRef = useRef< Record< string, string[] > >( {} );

	// Get all sites from sharedSites and governing site
	const sharedSites = window.OneSearchSettings?.sharedSites || [];
	const currentSiteUrl = window.OneSearchSettings?.currentSiteUrl || '';
	const [ initialSettings, setInitialSettings ] = useState<
		Record< string, SiteSearchSetting >
	>( {} );

	const brandSites: SiteInfo[] = sharedSites
		.filter( ( site ) => {
			const url = withTrailingSlash( site.url );
			const types = allPostTypes?.[ url ];
			return Array.isArray( types ) ? types.length > 0 : false;
		} )
		.map( ( site ) => ( {
			name: site.name,
			url: site.url,
			isGoverning: false,
		} ) );

	// Combine shared sites and governing site
	const allSites: SiteInfo[] = [
		// Governing site
		{
			name: __( 'Governing Site', 'onesearch' ),
			url: currentSiteUrl,
			isGoverning: true,
		},
		// Brand sites from shared sites
		...brandSites,
	];

	//  Check if site has indexable entities.
	const siteHasEntities = useCallback(
		( url: string ): boolean => {
			const normalizedUrl = withTrailingSlash( url );
			const entities = indexableEntities[ normalizedUrl ] || [];
			return Array.isArray( entities ) && entities.length > 0;
		},
		[ indexableEntities ]
	);

	// Auto-save search setting when entities are removed.
	useEffect( () => {
		if (
			! indexableEntities ||
			Object.keys( searchSettings ).length === 0
		) {
			return;
		}

		// Only run if indexableEntities changed
		if (
			JSON.stringify( prevIndexableEntitiesRef.current ) ===
			JSON.stringify( indexableEntities )
		) {
			return;
		}
		prevIndexableEntitiesRef.current = indexableEntities;

		let hasChanges = false;
		const updatedSettings: Record< string, SiteSearchSetting > = {
			...searchSettings,
		};

		Object.keys( searchSettings ).forEach( ( url ) => {
			const currentSetting = searchSettings[ url ];
			const hasEntities = siteHasEntities( url );

			if ( currentSetting?.algolia_enabled && ! hasEntities ) {
				updatedSettings[ url ] = {
					...currentSetting,
					algolia_enabled: false,
					searchable_sites: [],
				};
				hasChanges = true;
			}
		} );

		if ( hasChanges ) {
			setSearchSettings( updatedSettings );

			apiFetch< {
				onesearch_sites_search_settings: Record<
					string,
					SiteSearchSetting
				>;
			} >( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: {
					onesearch_sites_search_settings: updatedSettings,
				},
			} )
				.then( ( settings ) => {
					setNotice( {
						type: 'success',
						message: __(
							'Sites without indexable entities have been automatically disabled and saved.',
							'onesearch'
						),
					} );
					setInitialSettings(
						settings.onesearch_sites_search_settings
					);

					// To trigger re-rendering of search configuration component.
					setReloadKey( ( k ) => k + 1 );
				} )
				.catch( () => {
					setNotice( {
						type: 'error',
						message: __(
							'Failed to auto-save disabled sites.',
							'onesearch'
						),
					} );
				} );
		}
	}, [ indexableEntities, setNotice, siteHasEntities, searchSettings ] );

	// Load existing search settings
	useEffect( () => {
		apiFetch< {
			onesearch_sites_search_settings: Record<
				string,
				SiteSearchSetting
			>;
		} >( {
			path: '/wp/v2/settings',
		} )
			.then( ( settings ) => {
				if ( settings?.onesearch_sites_search_settings ) {
					setSearchSettings(
						settings.onesearch_sites_search_settings
					);
					setInitialSettings(
						settings.onesearch_sites_search_settings
					);
				}
			} )
			.catch( () => {
				setNotice( {
					type: 'error',
					message: __(
						'Failed to load search settings.',
						'onesearch'
					),
				} );
			} )
			.finally( () => {
				setLoading( false );
				setLocalNotice( null );
			} );
	}, [ reloadKey, setNotice ] );

	// Toggle Algolia for a site
	const handleSiteToggle = ( url: string, enabled: boolean ) => {
		if ( enabled && ! siteHasEntities( url ) ) {
			setLocalNotice( {
				type: 'warning',
				message: __(
					'This site cannot use Algolia search because no content types have been selected for indexing. Please configure indexable entities first, then enable Algolia search.',
					'onesearch'
				),
			} );
			return;
		}

		setSearchSettings( ( prev: Record< string, SiteSearchSetting > ) => ( {
			...prev,
			[ url ]: {
				algolia_enabled: enabled,
				searchable_sites: enabled ? [ url ] : [],
			},
		} ) );
	};

	// Toggle searchable sites for a site
	const handleSearchableSiteToggle = (
		parentSiteUrl: string,
		targetSiteUrl: string,
		checked: boolean
	) => {
		const isSelf =
			withTrailingSlash( targetSiteUrl ) ===
			withTrailingSlash( parentSiteUrl );

		if ( isSelf && ! checked ) {
			setLocalNotice( {
				type: 'warning',
				message: __(
					'The current site cannot be excluded from search results. It will always be included when Algolia search is enabled.',
					'onesearch'
				),
			} );
			return;
		}

		setSearchSettings( ( prev: Record< string, SiteSearchSetting > ) => {
			const prevSetting = prev[ parentSiteUrl ] || {
				algolia_enabled: false,
				searchable_sites: [],
			};
			const currentSearchables = prevSetting.searchable_sites;
			const newSearchables = checked
				? [ ...currentSearchables, targetSiteUrl ]
				: currentSearchables.filter(
						( url: string ) => url !== targetSiteUrl
				  );

			return {
				...prev,
				[ parentSiteUrl ]: {
					...prevSetting,
					searchable_sites: newSearchables,
				},
			};
		} );
	};

	// Handling bulk toggles.
	const handleBulkToggle = ( enable: boolean ) => {
		const newSettings: Record< string, SiteSearchSetting > = {};
		let skippedCount = 0;

		allSites.forEach( ( site ) => {
			const url = withTrailingSlash( site.url );

			// Only enable sites that have entities.
			const canEnable = enable ? siteHasEntities( url ) : true;

			if ( enable && ! canEnable ) {
				skippedCount++;
			}

			// Preserve previous searchable_sites when enabling.
			const prev = searchSettings[ url ] || {
				algolia_enabled: false,
				searchable_sites: [],
			};
			const prevSites = Array.isArray( prev?.searchable_sites )
				? // Keep only targets that still have entities.
				  prev.searchable_sites.filter( ( targetUrl: string ) =>
						siteHasEntities( targetUrl )
				  )
				: [];

			let sitesToReturn: string[] = [];

			if ( enable && canEnable ) {
				sitesToReturn = prevSites.length > 0 ? prevSites : [ url ];
			}

			newSettings[ url ] = {
				algolia_enabled: canEnable ? enable : false,
				searchable_sites: sitesToReturn,
			};
		} );

		setSearchSettings( newSettings );

		// Show notice if some sites were skipped
		if ( enable && skippedCount > 0 ) {
			setLocalNotice( {
				type: 'warning',
				message: __(
					'Some sites were skipped because they have no content types selected for indexing. Please configure indexable entities for these sites first.',
					'onesearch'
				),
			} );
		}
	};

	// Save the settings.
	const handleSave = async () => {
		setSaving( true );
		await apiFetch< {
			onesearch_sites_search_settings: Record<
				string,
				SiteSearchSetting
			>;
		} >( {
			path: '/wp/v2/settings',
			method: 'POST',
			data: {
				onesearch_sites_search_settings: searchSettings,
			},
		} )
			.then( ( settings ) => {
				setNotice( {
					type: 'success',
					message: __(
						'Search settings saved successfully.',
						'onesearch'
					),
				} );
				setInitialSettings( settings.onesearch_sites_search_settings );
			} )
			.catch( () => {
				setNotice( {
					type: 'error',
					message: __(
						'Failed to save search settings.',
						'onesearch'
					),
				} );
			} )
			.finally( () => {
				setSaving( false );
				setReloadKey( ( k ) => k + 1 );
			} );
	};

	// Stable comparison for dirty check
	const normalizeSettings = (
		settings: Record< string, SiteSearchSetting >
	): string => {
		const keys = Object.keys( settings ).sort();
		const normalized: Record< string, SiteSearchSetting > = {};
		keys.forEach( ( key ) => {
			normalized[ key ] = {
				algolia_enabled: settings[ key ]?.algolia_enabled ?? false,
				searchable_sites: [
					...( settings[ key ]?.searchable_sites ?? [] ),
				].sort(),
			};
		} );
		return JSON.stringify( normalized );
	};

	const isDirty =
		normalizeSettings( searchSettings ) !==
		normalizeSettings( initialSettings );

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<Card className="onesearch-card" style={ { marginTop: '30px' } }>
			<CardHeader>
				<h2 className="onesearch-title">
					{ __( 'Site Search Configuration', 'onesearch' ) }
				</h2>
				<div className="onesearch-controls">
					<Button
						variant="secondary"
						onClick={ () => handleBulkToggle( true ) }
						disabled={
							saving ||
							allSites.length === 0 ||
							isIndexableEntitiesSaving ||
							allSites.every( ( site ) => {
								const url = withTrailingSlash( site.url );
								return (
									searchSettings[ url ]?.algolia_enabled ||
									! siteHasEntities( url )
								);
							} )
						}
						className="onesearch-btn-enable-all"
					>
						{ __( 'Enable All', 'onesearch' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => handleBulkToggle( false ) }
						disabled={
							saving ||
							allSites.length === 0 ||
							isIndexableEntitiesSaving ||
							allSites.every( ( site ) => {
								const url = withTrailingSlash( site.url );
								return ! searchSettings[ url ]?.algolia_enabled;
							} )
						}
						className="onesearch-btn-disable-all"
					>
						{ __( 'Disable All', 'onesearch' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ handleSave }
						disabled={
							saving || ! isDirty || isIndexableEntitiesSaving
						}
						isBusy={ saving }
						className="onesearch-btn-save"
					>
						{ saving
							? __( 'Saving…', 'onesearch' )
							: __( 'Save Settings', 'onesearch' ) }
					</Button>
				</div>
			</CardHeader>
			<CardBody className="onesearch-body">
				{ localNotice && (
					<Notice
						status={ localNotice.type }
						isDismissible
						onRemove={ () => setLocalNotice( null ) }
						className="onesearch-notice"
					>
						{ localNotice.message }
					</Notice>
				) }

				{ allSites.length === 0 ? (
					<p className="onesearch-no-sites">
						{ __( 'No sites configured yet.', 'onesearch' ) }
					</p>
				) : (
					allSites.map( ( site ) => {
						const url = withTrailingSlash( site.url );
						const siteSettings: SiteSearchSetting = searchSettings[
							url
						] || {
							algolia_enabled: false,
							searchable_sites: [],
						};

						const hasEntities = siteHasEntities( url );

						return (
							<div
								key={ url }
								className={ `onesearch-site-card ${
									site.isGoverning
										? 'onesearch-site-governing'
										: ''
								} ${
									! hasEntities
										? 'onesearch-site-no-entities'
										: ''
								}` }
							>
								{ /* Site Header */ }
								<div className="onesearch-site-header">
									<div className="onesearch-site-info">
										<h3 className="onesearch-site-name">
											{ site.name }
										</h3>
										<p className="onesearch-entity-site-url">
											{ url }
										</p>
										<p className="onesearch-site-status">
											{ siteSettings.algolia_enabled
												? __(
														'Algolia search enabled',
														'onesearch'
												  )
												: __(
														'Using default WordPress search',
														'onesearch'
												  ) }
										</p>
										{ ! hasEntities && (
											<p className="onesearch-site-warning">
												{ __(
													'Please select entities for indexing to enable Algolia search',
													'onesearch'
												) }
											</p>
										) }
									</div>

									<div className="onesearch-site-toggle">
										<ToggleControl
											label=""
											checked={
												siteSettings.algolia_enabled
											}
											disabled={
												! hasEntities ||
												saving ||
												isIndexableEntitiesSaving
											}
											onChange={ ( enabled ) =>
												handleSiteToggle( url, enabled )
											}
											__nextHasNoMarginBottom
										/>
									</div>
								</div>

								{ /* Searchable Sites Selection */ }
								{ siteSettings.algolia_enabled && (
									<div className="onesearch-searchable-sites">
										<h4 className="onesearch-searchable-title">
											{ __(
												'Search from:',
												'onesearch'
											) }
										</h4>

										{ /* Sort sites with current site first */ }
										{ allSites
											.slice()
											.filter( ( singleSite ) => {
												const siteURL =
													withTrailingSlash(
														singleSite.url
													);
												const ents =
													indexableEntities[
														siteURL
													] || [];
												return (
													Array.isArray( ents ) &&
													ents.length > 0
												);
											} )
											.sort( ( a, b ) => {
												const aUrl = withTrailingSlash(
													a.url
												);
												const bUrl = withTrailingSlash(
													b.url
												);
												const currentUrl =
													withTrailingSlash( url );

												// Put current site first.
												if (
													aUrl === currentUrl &&
													bUrl !== currentUrl
												) {
													return -1;
												}
												if (
													bUrl === currentUrl &&
													aUrl !== currentUrl
												) {
													return 1;
												}

												// Sort others alphabetically.
												return a.name.localeCompare(
													b.name
												);
											} )
											.map( ( targetSite ) => {
												const targetSiteUrl =
													withTrailingSlash(
														targetSite.url
													);
												const isChecked =
													siteSettings.searchable_sites.includes(
														targetSiteUrl
													);
												const isSelf =
													targetSiteUrl === url;

												return (
													<div
														key={ targetSiteUrl }
														className={ `onesearch-searchable-item ${
															isSelf
																? 'onesearch-current-site'
																: ''
														}` }
													>
														<ToggleControl
															label={
																<div className="onesearch-searchable-label">
																	<div className="onesearch-searchable-name">
																		{
																			targetSite.name
																		}
																	</div>
																	<div className="onesearch-searchable-url">
																		{
																			targetSiteUrl
																		}
																		{ isSelf && (
																			<span className="onesearch-current-indicator">
																				{ __(
																					'(Current Site - Always Included)',
																					'onesearch'
																				) }
																			</span>
																		) }
																	</div>
																</div>
															}
															checked={
																isChecked ||
																isSelf
															}
															disabled={
																isSelf ||
																saving ||
																isIndexableEntitiesSaving
															}
															onChange={ (
																checked
															) =>
																handleSearchableSiteToggle(
																	url,
																	targetSiteUrl,
																	checked
																)
															}
															__nextHasNoMarginBottom
														/>
													</div>
												);
											} ) }
									</div>
								) }
							</div>
						);
					} )
				) }
			</CardBody>
		</Card>
	);
};

export default SiteSearchSettings;
