/**
 * WordPress dependencies
 */
/**
 * External dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Modal,
	Notice,
	Snackbar,
	Spinner,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from 'react';

/**
 * Internal dependencies
 */
import type { NoticeType } from '@/admin/settings/page';
import type { OneSearchPendingDisconnect } from '@/types/global';

const API_NAMESPACE = window.OneSearchSettings.restUrl + 'onesearch/v1';
const NONCE = window.OneSearchSettings.nonce as string;
const API_KEY = window.OneSearchSettings.api_key;

const SiteSettings = () => {
	const [ apiKey, setApiKey ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeType | null >( null );
	const [ governingSite, setGoverningSite ] = useState( '' );
	const [ showDisconnectionModal, setShowDisconnectionModal ] =
		useState( false );
	const [ pendingDisconnects, setPendingDisconnects ] = useState<
		OneSearchPendingDisconnect[]
	>( () => window.OneSearchSettings.pendingDisconnects ?? [] );

	const fetchApiKey = useCallback( async () => {
		setIsLoading( true );
		try {
			const response = await fetch( API_NAMESPACE + '/secret-key', {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE,
					'X-OneSearch-Token': API_KEY,
				},
			} );
			if ( ! response.ok ) {
				throw new Error( 'Network response was not ok' );
			}
			const data = await response.json();
			setApiKey( data?.secret_key || '' );
		} catch {
			setNotice( {
				type: 'error',
				message: __(
					'Failed to fetch API key. Please try again later.',
					'onesearch'
				),
			} );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	// regenerate api key using REST endpoint.
	const regenerateApiKey = useCallback( async () => {
		try {
			const response = await fetch( API_NAMESPACE + '/secret-key', {
				method: 'POST',
				headers: {
					'X-WP-Nonce': NONCE,
					'X-OneSearch-Token': API_KEY,
				},
			} );
			if ( ! response.ok ) {
				throw new Error( 'Network response was not ok' );
			}
			const data = await response.json();
			if ( data?.secret_key ) {
				setApiKey( data.secret_key );
				setNotice( {
					type: 'warning',
					message: __(
						'API key regenerated successfully. Please update your old key with this newly generated key to make sure plugin works properly.',
						'onesearch'
					),
				} );
			} else {
				setNotice( {
					type: 'error',
					message: __(
						'Failed to regenerate API key. Please try again later.',
						'onesearch'
					),
				} );
			}
		} catch {
			setNotice( {
				type: 'error',
				message: __(
					'Error regenerating API key. Please try again later.',
					'onesearch'
				),
			} );
		}
	}, [] );

	const fetchCurrentGoverningSite = useCallback( async () => {
		setIsLoading( true );
		try {
			const response = await fetch(
				`${ API_NAMESPACE }/governing-site?${ new Date().getTime() }`,
				{
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE,
						'X-OneSearch-Token': apiKey,
					},
				}
			);
			if ( ! response.ok ) {
				throw new Error( 'Network response was not ok' );
			}
			const data = await response.json();
			setGoverningSite( data?.governing_site_url || '' );
		} catch {
			setNotice( {
				type: 'error',
				message: __(
					'Failed to fetch governing site. Please try again later.',
					'onesearch'
				),
			} );
		} finally {
			setIsLoading( false );
		}
	}, [ apiKey ] );

	const retryDisconnect = useCallback( async ( siteUrl: string ) => {
		try {
			const response = await fetch(
				`${ API_NAMESPACE }/retry-disconnect`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE,
					},
					body: JSON.stringify( { site_url: siteUrl } ),
				}
			);

			if ( ! response.ok ) {
				throw new Error( 'Network response was not ok' );
			}

			const data = await response.json();

			// The response carries whatever is still undelivered, so it replaces the list.
			setPendingDisconnects( data?.pending ?? [] );

			setNotice(
				data?.success
					? {
							type: 'success',
							message: __( 'Disconnection sent.', 'onesearch' ),
					  }
					: {
							type: 'error',
							message: __(
								'The site still could not be notified. Please try again later.',
								'onesearch'
							),
					  }
			);
		} catch {
			setNotice( {
				type: 'error',
				message: __(
					'Failed to retry the disconnection. Please try again later.',
					'onesearch'
				),
			} );
		}
	}, [] );

	const deleteGoverningSiteConnection = useCallback( async () => {
		const previousGoverningSite = governingSite;

		try {
			const response = await fetch( `${ API_NAMESPACE }/governing-site`, {
				method: 'DELETE',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE,
					'X-OneSearch-Token': apiKey,
				},
			} );
			if ( ! response.ok ) {
				throw new Error( 'Network response was not ok' );
			}
			const data = await response.json();

			setGoverningSite( '' );
			setShowDisconnectionModal( false );

			if ( ! data?.remote_disconnected ) {
				setPendingDisconnects( [
					{
						site_url: '',
						message:
							data?.message ||
							sprintf(
								/* translators: %s: governing site URL. */
								__(
									'The governing site "%s" could not be notified that this site disconnected, and may still list this site as connected.',
									'onesearch'
								),
								previousGoverningSite
							),
					},
				] );
				return;
			}

			setNotice( {
				type: 'success',
				message: __(
					'Governing site disconnected successfully.',
					'onesearch'
				),
			} );
		} catch {
			setNotice( {
				type: 'error',
				message: __(
					'Failed to disconnect governing site. Please try again later.',
					'onesearch'
				),
			} );
			setShowDisconnectionModal( false );
		}
	}, [ apiKey, governingSite ] );

	const handleDisconnectGoverningSite = useCallback( async () => {
		setShowDisconnectionModal( true );
	}, [] );

	useEffect( () => {
		fetchApiKey();
		fetchCurrentGoverningSite();
	}, [ fetchApiKey, fetchCurrentGoverningSite ] );

	if ( isLoading ) {
		return <Spinner />;
	}

	return (
		<>
			{ pendingDisconnects.map( ( pending ) => (
				<Notice
					key={ pending.site_url || 'governing' }
					className="onesearch-disconnect-notice"
					status="warning"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Retry', 'onesearch' ),
							onClick: () => retryDisconnect( pending.site_url ),
						},
					] }
				>
					{ pending.message }
				</Notice>
			) ) }

			{ notice && (
				<Snackbar
					explicitDismiss={ false }
					onRemove={ () => setNotice( null ) }
					className={
						notice.type === 'error'
							? 'onesearch-error-notice'
							: 'onesearch-success-notice'
					}
				>
					{ notice.message }
				</Snackbar>
			) }

			<Card style={ { marginTop: '30px' } }>
				<CardHeader>
					<h2>{ __( 'API Key', 'onesearch' ) }</h2>
					<div>
						{ /* Copy to clipboard button */ }
						<Button
							variant="primary"
							onClick={ () => {
								navigator?.clipboard
									?.writeText( apiKey )
									.then( () => {
										setNotice( {
											type: 'success',
											message: __(
												'API key copied to clipboard.',
												'onesearch'
											),
										} );
									} )
									.catch( ( error ) => {
										setNotice( {
											type: 'error',
											message:
												__(
													'Failed to copy api key. Please try again.',
													'onesearch'
												) +
												' ' +
												error,
										} );
									} );
							} }
						>
							{ __( 'Copy API Key', 'onesearch' ) }
						</Button>
						{ /* Regenerate key button */ }
						<Button
							variant="secondary"
							onClick={ regenerateApiKey }
							style={ { marginLeft: '10px' } }
						>
							{ __( 'Regenerate API Key', 'onesearch' ) }
						</Button>
					</div>
				</CardHeader>
				<CardBody>
					<div>
						<TextareaControl
							value={ apiKey }
							disabled
							help={ __(
								'This key is used for secure communication with the Governing site.',
								'onesearch'
							) }
							__nextHasNoMarginBottom
							onChange={ () => {} } // to avoid ts warning
						/>
					</div>
				</CardBody>
			</Card>
			<Card
				className="governing-site-connection"
				style={ { marginTop: '30px' } }
			>
				<CardHeader>
					<h2>{ __( 'Governing Site Connection', 'onesearch' ) }</h2>
					<Button
						variant="secondary"
						isDestructive
						onClick={ handleDisconnectGoverningSite }
						disabled={
							governingSite.trim().length === 0 || isLoading
						}
					>
						{ __( 'Disconnect Governing Site', 'onesearch' ) }
					</Button>
				</CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Governing Site URL', 'onesearch' ) }
						value={ governingSite }
						disabled
						help={ __(
							'This is the URL of the Governing site this Brand site is connected to.',
							'onesearch'
						) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						onChange={ () => {} } // to avoid ts warning
					/>
				</CardBody>
			</Card>

			{ showDisconnectionModal && (
				<Modal
					title={ __( 'Disconnect Governing Site', 'onesearch' ) }
					onRequestClose={ () => setShowDisconnectionModal( false ) }
					shouldCloseOnClickOutside
				>
					<p>
						{ __(
							'Are you sure you want to disconnect from the governing site? This action cannot be undone.',
							'onesearch'
						) }
					</p>
					<div
						style={ {
							display: 'flex',
							justifyContent: 'flex-end',
							marginTop: '20px',
							gap: '16px',
						} }
					>
						<Button
							variant="secondary"
							onClick={ () => setShowDisconnectionModal( false ) }
						>
							{ __( 'Cancel', 'onesearch' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ deleteGoverningSiteConnection }
						>
							{ __( 'Disconnect', 'onesearch' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
};

export default SiteSettings;
