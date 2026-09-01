/**
 * External dependencies
 */
import { useState, useEffect } from 'react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Modal, Notice, Button, SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { SiteType } from '../../types/global';

// Re-export for backward compatibility
export type { SiteType } from '../../types/global';

const BRAND_SITE = 'brand-site' as const;
const GOVERNING_SITE = 'governing-site' as const;

interface NoticeState {
	type: 'success' | 'error' | 'warning' | 'info';
	message: string;
}

// WordPress provides snake_case keys here. Rename to camelCase for local use.
const {
	nonce,
	setup_url: setupUrl,
	site_type: initialSiteType,
} = window.OneSearchOnboarding;

/**
 * Create NONCE middleware for apiFetch
 */
apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

const SiteTypeSelector = ( {
	value,
	setSiteType,
}: {
	value: SiteType;
	setSiteType: ( v: SiteType ) => void;
} ) => (
	<SelectControl
		label={ __( 'Site Type', 'onesearch' ) }
		value={ value }
		help={ __(
			"Choose your site's primary purpose. This setting cannot be changed later and affects available features and configurations.",
			'onesearch'
		) }
		onChange={ ( v: SiteType ) => {
			setSiteType( v );
		} }
		__nextHasNoMarginBottom
		__next40pxDefaultSize
		options={ [
			{ label: __( 'Select…', 'onesearch' ), value: '' },
			{ label: __( 'Brand Site', 'onesearch' ), value: BRAND_SITE },
			{
				label: __( 'Governing site', 'onesearch' ),
				value: GOVERNING_SITE,
			},
		] }
	/>
);

const OnboardingScreen = () => {
	const [ siteType, setSiteType ] = useState< SiteType >(
		initialSiteType || ''
	);
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ isSaving, setIsSaving ] = useState( false );

	useEffect( () => {
		apiFetch< { onesearch_site_type?: SiteType } >( {
			path: '/wp/v2/settings',
		} )
			.then( ( settings ) => {
				if ( settings?.onesearch_site_type ) {
					setSiteType( settings.onesearch_site_type );
				}
			} )
			.catch( () => {
				setNotice( {
					type: 'error',
					message: __( 'Error fetching site type.', 'onesearch' ),
				} );
			} );
	}, [] ); // for initial component mount

	const handleSiteTypeChange = async ( value: SiteType ) => {
		// Optimistically set site type.
		setSiteType( value );
		setIsSaving( true );

		try {
			await apiFetch< { onesearch_site_type?: SiteType } >( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { onesearch_site_type: value },
			} ).then( ( settings ) => {
				if ( ! settings?.onesearch_site_type ) {
					throw new Error( 'No site type in response' );
				}

				setSiteType( settings.onesearch_site_type );

				// Redirect user to setup page.
				if ( setupUrl ) {
					window.location.href = setupUrl;
				}
			} );
		} catch {
			setNotice( {
				type: 'error',
				message: __( 'Error setting site type.', 'onesearch' ),
			} );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		/**
		 * A real dialog, not a div that merely looks like one: `Modal` supplies
		 * `role="dialog"`, names it from `title`, moves focus in, constrains
		 * tabbing to it and restores focus on close.
		 *
		 * Choosing a site type is required before the plugin can do anything, so
		 * every dismissal route is closed off and `onRequestClose` has nothing
		 * to do.
		 */
		<Modal
			title={ __( 'OneSearch', 'onesearch' ) }
			onRequestClose={ () => {} }
			isDismissible={ false }
			shouldCloseOnEsc={ false }
			shouldCloseOnClickOutside={ false }
			className="onesearch-onboarding-modal"
		>
			{ !! notice?.message && (
				<Notice
					status={ notice?.type ?? 'success' }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice?.message }
				</Notice>
			) }

			<div className="onesearch-onboarding-page">
				<SiteTypeSelector
					value={ siteType }
					setSiteType={ setSiteType }
				/>
				<Button
					variant="primary"
					onClick={ () => handleSiteTypeChange( siteType ) }
					disabled={ isSaving || ! siteType }
					style={ { marginTop: '1.5rem' } }
					className={ isSaving ? 'is-busy' : '' }
				>
					{ __( 'Select Current Site Type', 'onesearch' ) }
				</Button>
			</div>
		</Modal>
	);
};

export default OnboardingScreen;
