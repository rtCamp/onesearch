/**
 * WordPress dependencies
 */
/**
 * External dependencies
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Notice, Snackbar } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import SiteTable from '@/components/SiteTable';
import SiteModal from '@/components/SiteModal';
import SiteSettings from '@/components/SiteSettings';
import AlgoliaSettings from '@/components/AlgoliaSettings';
import SiteTypeSettings from '@/components/SiteTypeSettings';
import type { SiteType } from '@/types/global';

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

interface DeleteSiteResponse {
	governing_site_notified?: boolean;
	message?: string;
}

const SettingsPage = () => {
	const [ showModal, setShowModal ] = useState( false );
	const [ editingIndex, setEditingIndex ] = useState< EditingIndex >( null );
	const [ sites, setSites ] = useState< BrandSite[] >(
		( window.OneSearchSettings.sharedSites ?? [] ) as BrandSite[]
	);
	const [ formData, setFormData ] = useState< BrandSite >( defaultBrandSite );
	const [ notice, setNotice ] = useState< NoticeType | null >( null );
	// Kept until dismissed, since each one asks the admin to finish disconnecting a brand site.
	const [ disconnectNotices, setDisconnectNotices ] = useState<
		NoticeType[]
	>( [] );

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
			.catch( ( error: { code?: string; message?: string } ) => {
				setNotice( {
					type: 'error',
					// A REST error has the server's reason, like a brand site that failed its health check.
					message:
						( error?.code && error.message ) ||
						__( 'Failed to update shared sites', 'onesearch' ),
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

		if ( ! removedSite ) {
			return;
		}

		let response: DeleteSiteResponse;

		try {
			// The server also tells the brand site to disconnect, with the key it stored.
			response = await apiFetch< DeleteSiteResponse >( {
				path: `${ SHARED_SITES_ENDPOINT }/${ encodeURIComponent(
					removedSite.id ?? ''
				) }`,
				method: 'DELETE',
			} );
		} catch {
			setNotice( {
				type: 'error',
				message: __( 'Failed to update shared sites', 'onesearch' ),
			} );
			return;
		}

		setSites( ( current ) =>
			current.filter( ( site ) => site.id !== removedSite.id )
		);

		if ( response?.governing_site_notified === false && response.message ) {
			const disconnectNotice: NoticeType = {
				type: 'warning',
				message: response.message,
			};

			setDisconnectNotices( ( notices ) => [
				...notices.filter(
					( item ) => item.message !== disconnectNotice.message
				),
				disconnectNotice,
			] );
		}
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

			{ ! SITE_TYPE && <SiteTypeSettings /> }

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
