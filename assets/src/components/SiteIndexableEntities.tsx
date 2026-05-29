/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Modal,
	__experimentalText as Text,
} from '@wordpress/components';

/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Internal dependencies
 */
import type { NoticeType } from '@/admin/settings/page';
import { API_NAMESPACE, NONCE, withTrailingSlash } from '@/js/utils';
import type { OneSearchSharedSite } from '@/types/global';
import MultiSelectChips from './MultiSelectChips';
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

interface SiteJob {
	site_name: string;
	site_url: string;
	job_id: string;
}

interface ReIndexResponse {
	success: boolean;
	message?: string;
	job_id?: string;
	jobs?: SiteJob[];
	data?: {
		status?: number;
	};
}

interface JobStatus {
	id: string;
	status: string;
	progress: number;
	progress_total: number;
	progress_percent: number;
	error?: string;
	child_ids?: string[];
	children_completed?: number;
	children_total?: number;
	data?: Record< string, unknown >;
	group?: string;
	updated_at?: number;
}

interface SiteJobState {
	site: SiteJob;
	reindexJob: JobStatus | null;
	children: JobStatus[];
	expanded: boolean;
}

const STATUS_LABELS: Record< string, string > = {
	pending: 'Pending',
	running: 'Running',
	completed: 'Completed',
	failed: 'Failed',
	cancelled: 'Cancelled',
};

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
	const [ siteStates, setSiteStates ] = useState< SiteJobState[] >( [] );
	const [ history, setHistory ] = useState< JobStatus[] >( [] );
	const [ retrying, setRetrying ] = useState< Record< string, boolean > >(
		{}
	);
	const [ cancelling, setCancelling ] = useState( false );
	const [ showHistory, setShowHistory ] = useState( false );
	const intervalRef = useRef< ReturnType< typeof setInterval > | null >(
		null
	);
	const siteStatesRef = useRef< SiteJobState[] >( [] );

	const entitySelectorsDisabled = saving || reindexing;

	const stopPolling = useCallback( () => {
		if ( intervalRef.current ) {
			clearInterval( intervalRef.current );
			intervalRef.current = null;
		}
	}, [] );

	const fetchJobWithChildren = useCallback(
		async (
			jobId: string
		): Promise< {
			reindexJob: JobStatus | null;
			children: JobStatus[];
		} | null > => {
			try {
				const [ jobRes, childRes ] = await Promise.all( [
					fetch(
						`${ API_NAMESPACE }/jobs/${ encodeURIComponent(
							jobId
						) }`,
						{ headers: { 'X-WP-Nonce': NONCE } }
					),
					fetch(
						`${ API_NAMESPACE }/jobs/${ encodeURIComponent(
							jobId
						) }/children`,
						{ headers: { 'X-WP-Nonce': NONCE } }
					),
				] );
				const jobData = await jobRes.json();
				const childData = await childRes.json();
				return {
					reindexJob: jobData.job || null,
					children: childData.children || [],
				};
			} catch {
				return null;
			}
		},
		[]
	);

	const fetchRemoteJobStatus = useCallback(
		async (
			siteUrl: string,
			jobId: string
		): Promise< {
			reindexJob: JobStatus | null;
			children: JobStatus[];
		} | null > => {
			try {
				const params = new URLSearchParams( {
					site_url: siteUrl,
					job_id: jobId,
				} );
				const res = await fetch(
					`${ API_NAMESPACE }/jobs/remote-status?${ params.toString() }`,
					{ headers: { 'X-WP-Nonce': NONCE } }
				);
				const data = await res.json();
				return {
					reindexJob: data.job || null,
					children: data.children || [],
				};
			} catch {
				return null;
			}
		},
		[]
	);

	const fetchHistory = useCallback( async () => {
		try {
			const res = await fetch( `${ API_NAMESPACE }/jobs/history`, {
				headers: { 'X-WP-Nonce': NONCE },
			} );
			const data = await res.json();
			setHistory( data.jobs || [] );
		} catch {
			// Silently fail for history.
		}
	}, [] );

	const isCurrentSite = useCallback(
		( siteUrl: string ) => withTrailingSlash( siteUrl ) === currentSiteUrl,
		[ currentSiteUrl ]
	);

	const pollAllSites = useCallback( async () => {
		const current = siteStatesRef.current;
		if ( current.length === 0 ) {
			return;
		}

		const updated = await Promise.all(
			current.map( async ( s ) => {
				if ( ! s.site.job_id ) {
					return s;
				}
				if ( isCurrentSite( s.site.site_url ) ) {
					const result = await fetchJobWithChildren( s.site.job_id );
					if ( result ) {
						return { ...s, ...result };
					}
					return s;
				}
				const result = await fetchRemoteJobStatus(
					s.site.site_url,
					s.site.job_id
				);
				if ( result ) {
					return { ...s, ...result };
				}
				return s;
			} )
		);

		setSiteStates( ( prev ) => {
			// Preserve expanded state from the latest render.
			const expandedMap = new Map(
				prev.map( ( s ) => [ s.site.site_url, s.expanded ] )
			);
			return updated.map( ( s ) => ( {
				...s,
				expanded: expandedMap.get( s.site.site_url ) ?? s.expanded,
			} ) );
		} );

		const allTerminal = updated.every(
			( s ) =>
				! s.reindexJob ||
				[ 'completed', 'failed', 'cancelled' ].includes(
					s.reindexJob.status
				)
		);
		if ( allTerminal ) {
			stopPolling();
			setReindexing( false );
			fetchHistory();
		}
	}, [
		isCurrentSite,
		fetchJobWithChildren,
		fetchRemoteJobStatus,
		stopPolling,
		fetchHistory,
	] );

	useEffect( () => {
		siteStatesRef.current = siteStates;
	}, [ siteStates ] );

	useEffect( () => {
		return () => stopPolling();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleRetry = async ( childJobId: string ) => {
		setRetrying( ( prev ) => ( { ...prev, [ childJobId ]: true } ) );
		try {
			const res = await fetch(
				`${ API_NAMESPACE }/jobs/${ encodeURIComponent(
					childJobId
				) }/retry`,
				{
					method: 'POST',
					headers: { 'X-WP-Nonce': NONCE },
				}
			);
			const data = await res.json();
			if ( data.success ) {
				setNotice( {
					type: 'success',
					message: __( 'Batch retry scheduled.', 'onesearch' ),
				} );
				// Refresh the governing site state.
				setSiteStates( ( prev ) =>
					prev.map( ( s ) => {
						if ( isCurrentSite( s.site.site_url ) ) {
							return {
								...s,
								children: s.children.map( ( c ) =>
									c.id === childJobId
										? {
												...c,
												status: 'pending',
												error: '',
										  }
										: c
								),
							};
						}
						return s;
					} )
				);
			} else {
				setNotice( {
					type: 'error',
					message: data.message || __( 'Retry failed.', 'onesearch' ),
				} );
			}
		} catch {
			setNotice( {
				type: 'error',
				message: __( 'Retry request failed.', 'onesearch' ),
			} );
		} finally {
			setRetrying( ( prev ) => ( { ...prev, [ childJobId ]: false } ) );
		}
	};

	const toggleExpand = ( siteUrl: string ) => {
		const normalized = withTrailingSlash( siteUrl );
		setSiteStates( ( prev ) =>
			prev.map( ( s ) =>
				withTrailingSlash( s.site.site_url ) === normalized
					? { ...s, expanded: ! s.expanded }
					: s
			)
		);
	};

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
			setSiteStates( [] );
			stopPolling();
			setShowHistory( false );
			fetchHistory();

			const response = await fetch( `${ API_NAMESPACE }/re-index`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE,
				},
			} );

			const data: ReIndexResponse = await response.json();
			if ( data.success && data.jobs && data.jobs.length > 0 ) {
				const initial: SiteJobState[] = data.jobs.map( ( site ) => ( {
					site,
					reindexJob: null,
					children: [],
					expanded: false,
				} ) );
				setSiteStates( initial );
				siteStatesRef.current = initial;

				// Start polling.
				const interval = setInterval( () => pollAllSites(), 2000 );
				intervalRef.current = interval;
				// Do an immediate fetch.
				pollAllSites();
				return true;
			}

			const errorMsg =
				data.message || __( 'Unknown error.', 'onesearch' );
			setReindexing( false );
			setShowReindexingModal( false );
			setNotice( {
				message: errorMsg,
				type: 'error',
			} );
		} catch ( error: unknown ) {
			const message =
				error instanceof Error ? error.message : String( error );
			setReindexing( false );
			setShowReindexingModal( false );
			setNotice( {
				message,
				type: 'error',
			} );
		}
		return false;
	};

	const handleModalClose = () => {
		setShowReindexingModal( false );
	};

	const handleCancelJob = async () => {
		setCancelling( true );
		const activeJobs = siteStates.filter(
			( s ) =>
				s.reindexJob &&
				! [ 'completed', 'failed', 'cancelled' ].includes(
					s.reindexJob.status
				) &&
				s.site.job_id
		);

		await Promise.all(
			activeJobs.map( ( s ) =>
				fetch(
					`${ API_NAMESPACE }/jobs/${ encodeURIComponent(
						s.site.job_id
					) }`,
					{
						method: 'DELETE',
						headers: { 'X-WP-Nonce': NONCE },
					}
				).catch( () => {} )
			)
		);

		stopPolling();
		setReindexing( false );
		setSiteStates( [] );
		setCancelling( false );
		setShowReindexingModal( false );
	};

	const isDirty =
		JSON.stringify( normalizeEntities( selectedEntities ) ) !==
		JSON.stringify( savedEntities );

	const toMultiSelectOptions = (
		options: PostTypeOption[]
	): Array< { slug: string; label: string; restBase: string } > => {
		return options.map( ( opt ) => ( {
			slug: opt.slug,
			label: opt.label ?? opt.slug,
			restBase: opt.restBase ?? opt.slug,
		} ) );
	};

	const renderJobStatusBadge = ( status: string, size = 'normal' ) => (
		<span
			className={ `onesearch-job-status onesearch-job-status--${ status }${
				size === 'small' ? ' onesearch-job-status--small' : ''
			}` }
		>
			{ STATUS_LABELS[ status ] || status }
		</span>
	);

	const renderProgressBar = ( percent: number ) => (
		<div className="onesearch-job-progress-bar onesearch-job-progress-bar--small">
			<div
				className="onesearch-job-progress-fill"
				style={ {
					width: `${ Math.max( 0, Math.min( 100, percent ) ) }%`,
				} }
			/>
		</div>
	);

	const renderSiteProgress = ( state: SiteJobState ) => {
		const j = state.reindexJob;
		if ( ! j ) {
			return <Text variant="muted">…</Text>;
		}
		const children = state.children;
		const childTotal = children.length;

		if ( childTotal > 0 ) {
			const done = children.filter( ( c ) =>
				[ 'completed', 'failed' ].includes( c.status )
			).length;
			return (
				<span className="onesearch-job-progress-text">
					{ done } / { childTotal } { __( 'batches', 'onesearch' ) }
				</span>
			);
		}
		return renderJobStatusBadge( j.status );
	};

	const renderBatchRow = ( child: JobStatus, idx: number ) => (
		<div key={ child.id } className="onesearch-batch-row">
			<span className="onesearch-batch-label">
				{ sprintf(
					/* translators: %d: batch number */
					__( 'Batch %d', 'onesearch' ),
					idx + 1
				) }
			</span>
			{ renderJobStatusBadge( child.status, 'small' ) }
			{ child.status === 'running' || child.status === 'pending'
				? renderProgressBar( child.progress_percent || 0 )
				: null }
			{ child.error && (
				<span className="onesearch-batch-error">
					{ child.error.substring( 0, 80 ) }
				</span>
			) }
			{ child.status === 'failed' && (
				<Button
					variant="secondary"
					size="small"
					isBusy={ !! retrying[ child.id ] }
					disabled={ !! retrying[ child.id ] }
					onClick={ () => void handleRetry( child.id ) }
				>
					{ retrying[ child.id ]
						? __( 'Retrying…', 'onesearch' )
						: __( 'Retry', 'onesearch' ) }
				</Button>
			) }
		</div>
	);

	const renderSiteRow = ( state: SiteJobState ) => {
		const hasChildren = state.children.length > 0;
		const canExpand =
			hasChildren ||
			( !! state.reindexJob &&
				state.reindexJob.status !== 'completed' &&
				state.reindexJob.status !== 'cancelled' );

		return (
			<div key={ state.site.site_url } className="onesearch-job-site-row">
				<div
					className="onesearch-job-site-header"
					onClick={ () =>
						canExpand ? toggleExpand( state.site.site_url ) : null
					}
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' && canExpand ) {
							toggleExpand( state.site.site_url );
						}
					} }
					role={ canExpand ? 'button' : undefined }
					tabIndex={ canExpand ? 0 : undefined }
					style={ { cursor: canExpand ? 'pointer' : 'default' } }
				>
					<span className="onesearch-job-site-name">
						{ canExpand && (
							<span className="onesearch-job-expand-icon">
								{ state.expanded ? '▾ ' : '▸ ' }
							</span>
						) }
						{ state.site.site_name }
					</span>
					<Text variant="muted" className="onesearch-job-site-url">
						{ state.site.site_url }
					</Text>
					<div className="onesearch-job-site-status">
						{ renderSiteProgress( state ) }
					</div>
				</div>

				{ state.expanded && hasChildren && (
					<div className="onesearch-batch-list">
						{ state.children.map( ( child, idx ) =>
							renderBatchRow( child, idx )
						) }
					</div>
				) }

				{ state.expanded &&
					! hasChildren &&
					state.reindexJob &&
					state.reindexJob.status !== 'completed' && (
						<Text variant="muted" className="onesearch-batch-list">
							{ __( 'Waiting for batch creation…', 'onesearch' ) }
						</Text>
					) }
			</div>
		);
	};

	const renderHistoryRow = ( job: JobStatus ) => (
		<div key={ job.id } className="onesearch-job-site-row">
			<div className="onesearch-job-site-header">
				<span className="onesearch-job-site-name">
					{ job.id.substring( 0, 20 ) }…
				</span>
				<Text variant="muted">{ job.group || 'reindex' }</Text>
				<div className="onesearch-job-site-status">
					{ renderJobStatusBadge( job.status, 'small' ) }
					{ job.progress_total > 0 && (
						<span className="onesearch-job-progress-text">
							{ job.progress }/{ job.progress_total }
						</span>
					) }
				</div>
			</div>
		</div>
	);

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
											fetchHistory();
										}
										return ! prev;
									} );
								} }
								disabled={
									isEmptySavedEntities() && ! reindexing
								}
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
								disabled={ entitySelectorsDisabled }
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
										disabled={ entitySelectorsDisabled }
									/>
								</div>
							) }
						</div>
					) ) }
				</CardBody>
			</Card>
			{ showReindexingModal && (
				<Modal
					title={
						reindexing
							? __( 'Indexing Progress', 'onesearch' )
							: __( 'Re-index saved entities', 'onesearch' )
					}
					onRequestClose={ handleModalClose }
					shouldCloseOnClickOutside={ false }
					size="large"
				>
					{ ! reindexing && siteStates.length === 0 && (
						<>
							<p>
								{ __(
									'Re-indexing will only index the entities you have previously saved. To re-index modified entities, please make sure you have saved them.',
									'onesearch'
								) }
							</p>
							<div className="onesearch-modal-actions">
								<Button
									variant="primary"
									onClick={ () => handleReIndex() }
								>
									{ __( 'Re-index', 'onesearch' ) }
								</Button>
							</div>
						</>
					) }

					{ siteStates.length > 0 && (
						<div className="onesearch-job-panel">
							<div className="onesearch-job-panel-header">
								<h3>
									{ __( 'Index Job', 'onesearch' ) }{ ' ' }
									<code>
										{ siteStates[ 0 ]?.reindexJob?.id?.substring(
											0,
											20
										) ||
											siteStates[ 0 ]?.site?.job_id?.substring(
												0,
												20
											) ||
											'…' }
										…
									</code>
								</h3>
								<Button
									variant="secondary"
									size="small"
									onClick={ handleCancelJob }
									isBusy={ cancelling }
									disabled={ cancelling }
									className="onesearch-btn-cancel"
								>
									{ cancelling
										? __( 'Cancelling…', 'onesearch' )
										: __( 'Cancel Job', 'onesearch' ) }
								</Button>
							</div>

							<div className="onesearch-job-panel-body">
								{ siteStates.map( ( s ) =>
									renderSiteRow( s )
								) }
							</div>
						</div>
					) }

					{ /* History section */ }
					<details
						className="onesearch-job-history"
						open={ showHistory }
						onToggle={ ( e ) =>
							setShowHistory(
								( e.target as HTMLDetailsElement ).open
							)
						}
					>
						<summary className="onesearch-job-history-header">
							{ sprintf(
								/* translators: %d: count */
								__( 'Job History (%d)', 'onesearch' ),
								history.length
							) }
						</summary>
						<div className="onesearch-job-panel-body">
							{ history.length === 0 && (
								<Text variant="muted">
									{ __( 'No past jobs.', 'onesearch' ) }
								</Text>
							) }
							{ history.map( ( job ) =>
								renderHistoryRow( job )
							) }
						</div>
					</details>
				</Modal>
			) }
		</>
	);
};

export default SiteIndexableEntities;
