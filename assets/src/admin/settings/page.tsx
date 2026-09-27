/**
 * WordPress dependencies
 */
/**
 * External dependencies
 */
import { useState, useEffect } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Snackbar } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import SiteTable from '@/components/SiteTable';
import SiteModal from '@/components/SiteModal';
import SiteSettings from '@/components/SiteSettings';
import AlgoliaSettings from '@/components/AlgoliaSettings';
import { REST_NAMESPACE, withTrailingSlash } from '@/js/utils';
import type { SiteType } from '../onboarding/page';

export interface NoticeType {
	type: 'success' | 'error' | 'warning' | 'info';
	message: string;
}

export interface BrandSite {
	id?: string;
	name: string;
	url: string;
	api_key: string;
}

export const defaultBrandSite: BrandSite = {
	name: '',
	url: '',
	api_key: '',
};

export type EditingIndex = number | null;

const NONCE = window.OneSearchSettings.nonce;
const SITE_TYPE = ( window.OneSearchSettings.siteType as SiteType ) || '';
const SHARED_SITES_ENDPOINT = '/onesearch/v1/shared-sites';

/**
 * Create NONCE middleware for apiFetch
 */
apiFetch.use( apiFetch.createNonceMiddleware( NONCE ) );

/**
 * Tells a removed brand site to disconnect from this governing site.
 *
 * @param site The removed brand site.
 *
 * @return A notice for the admin if the brand site wasn't disconnected, otherwise null.
 */
const disconnectRemovedBrandSite = async (
	site: BrandSite
): Promise< NoticeType | null > => {
	const siteUrl = withTrailingSlash( site.url );
	let status = 0;

	try {
		const response = await fetch(
			`${ siteUrl }wp-json/${ REST_NAMESPACE }/brand-site`,
			{
				method: 'DELETE',
				headers: {
					'Content-Type': 'application/json',
					'X-OneSearch-Token': site.api_key,
					'X-OneSearch-Site-URL':
						window.OneSearchSettings.currentSiteUrl,
				},
			}
		);

		if ( response.ok ) {
			return null;
		}

		status = response.status;
	} catch {
		// The brand site couldn't be reached.
	}

	// The brand site only authenticates the governing site it is connected to.
	if ( status === 401 || status === 403 ) {
		return {
			type: 'warning',
			message: sprintf(
				/* translators: %s: brand site name. */
				__(
					'%s was removed, but it did not recognize this site, so it may already be disconnected. If it still shows this site under Governing Site Connection, disconnect it there.',
					'onesearch'
				),
				site.name
			),
		};
	}

	return {
		type: 'warning',
		message: sprintf(
			/* translators: %s: brand site name. */
			__(
				'%s was removed, but it could not be notified. To finish disconnecting, click Disconnect Governing Site in its OneSearch settings.',
				'onesearch'
			),
			site.name
		),
	};
};

const SettingsPage = () => {
	const [ showModal, setShowModal ] = useState( false );
	const [ editingIndex, setEditingIndex ] = useState< EditingIndex >( null );
	const [ sites, setSites ] = useState< BrandSite[] >( [] );
	const [ formData, setFormData ] = useState< BrandSite >( defaultBrandSite );
	const [ notice, setNotice ] = useState< NoticeType | null >( null );
	// Kept until dismissed, since each one asks the admin to finish disconnecting a brand site.
	const [ disconnectNotices, setDisconnectNotices ] = useState<
		NoticeType[]
	>( [] );

	useEffect( () => {
		apiFetch< { shared_sites?: BrandSite[] } >( {
			path: SHARED_SITES_ENDPOINT,
		} )
			.then( ( data ) => {
				if ( data?.shared_sites ) {
					setSites( data?.shared_sites );
				}
			} )
			.catch( () => {
				setNotice( {
					type: 'error',
					message: __( 'Error fetching settings data.', 'onesearch' ),
				} );
			} );
	}, [] ); // Empty dependency array to run only once on mount

	useEffect( () => {
		if ( SITE_TYPE === 'governing-site' && sites.length > 0 ) {
			document.body.classList.remove( 'onesearch-missing-brand-sites' );
		}
	}, [ sites ] );

	const handleFormSubmit = async (): Promise< boolean > => {
		const updated: BrandSite[] =
			editingIndex !== null
				? sites.map( ( item, i ) =>
						i === editingIndex ? formData : item
				  )
				: [ ...sites, formData ];

		return apiFetch< { shared_sites?: BrandSite[] } >( {
			path: SHARED_SITES_ENDPOINT,
			method: 'POST',
			data: { sites_data: updated },
		} )
			.then( ( data ) => {
				if ( ! data?.shared_sites ) {
					throw new Error( 'No shared sites in response' );
				}

				setSites( data.shared_sites );

				if ( data.shared_sites.length === 0 ) {
					// Reloading causes the menus etc to reflect the missing sites.
					window.location.reload();
				}

				setNotice( {
					type: 'success',
					message: __(
						'Brand Site saved successfully.',
						'onesearch'
					),
				} );
				return true;
			} )
			.catch( () => {
				setNotice( {
					type: 'error',
					message: __( 'Failed to update shared sites', 'onesearch' ),
				} );
				return false;
			} )
			.finally( () => {
				setFormData( defaultBrandSite );
				setShowModal( false );
				setEditingIndex( null );
			} );
	};

	const handleDelete = async ( index: number | null ): Promise< void > => {
		const removedSite = index !== null ? sites[ index ] : undefined;
		const updated: BrandSite[] = sites.filter( ( _, i ) => i !== index );

		apiFetch< { shared_sites?: BrandSite[] } >( {
			path: SHARED_SITES_ENDPOINT,
			method: 'POST',
			data: { sites_data: updated },
		} )
			.then( async ( data ) => {
				if ( ! data?.shared_sites ) {
					throw new Error( 'No shared sites in response' );
				}
				setSites( data.shared_sites );

				const disconnectNotice = removedSite
					? await disconnectRemovedBrandSite( removedSite )
					: null;

				if ( disconnectNotice ) {
					setDisconnectNotices( ( notices ) => [
						...notices.filter(
							( item ) =>
								item.message !== disconnectNotice.message
						),
						disconnectNotice,
					] );
				}

				if ( data.shared_sites.length > 0 ) {
					document.body.classList.remove(
						'onesearch-missing-brand-sites'
					);
				} else if ( ! disconnectNotice ) {
					/*
					 * Reloading causes the menus etc to reflect the missing sites.
					 *
					 * Skipped when there's a notice to show, since reloading would clear it.
					 */
					window.location.reload();
				}
			} )
			.catch( () => {
				setNotice( {
					type: 'error',
					message: __( 'Failed to update shared sites', 'onesearch' ),
				} );
			} );
	};

	return (
		<>
			{ !! notice && notice?.message?.length > 0 && (
				<Snackbar
					explicitDismiss={ false }
					onRemove={ () => setNotice( null ) }
					className={
						notice?.type === 'error'
							? 'onesearch-error-notice'
							: 'onesearch-success-notice'
					}
				>
					{ notice?.message }
				</Snackbar>
			) }

			{ disconnectNotices.map( ( disconnectNotice ) => (
				<Notice
					key={ disconnectNotice.message }
					status={ disconnectNotice.type }
					isDismissible
					onRemove={ () =>
						setDisconnectNotices( ( notices ) =>
							notices.filter(
								( item ) => item !== disconnectNotice
							)
						)
					}
				>
					{ disconnectNotice.message }
				</Notice>
			) ) }

			{ SITE_TYPE === 'brand-site' && <SiteSettings /> }

			{ SITE_TYPE === 'governing-site' && (
				<SiteTable
					sites={ sites }
					onEdit={ setEditingIndex }
					onDelete={ handleDelete }
					setFormData={ setFormData }
					setShowModal={ setShowModal }
				/>
			) }

			{ SITE_TYPE === 'governing-site' && (
				<AlgoliaSettings setNotice={ setNotice } />
			) }

			{ showModal && (
				<SiteModal
					formData={ formData }
					setFormData={ setFormData }
					onSubmit={ handleFormSubmit }
					onClose={ () => {
						setShowModal( false );
						setEditingIndex( null );
						setFormData( defaultBrandSite );
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
		</>
	);
};

export default SettingsPage;
