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
	batch_count?: number;
}

interface ReIndexResponse {
	success: boolean;
	message?: string;
	job_id?: string;
	batch_count?: number;
	jobs?: SiteJob[];
	data?: {
		status?: number;
	};
}

interface HistoryResponse {
	success: boolean;
	jobs: JobStatus[];
	total: number;
	page: number;
	per_page: number;
	total_pages: number;
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
	children_failed?: number;
	children_total?: number;
	data?: {
		total_batches?: number;
		sites?: SiteJob[];
		[ key: string ]: unknown;
	};
	group?: string;
	created_at?: number;
	updated_at?: number;
	finished_at?: number;
	cancelled_at?: number;
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

const TERMINAL_JOB_STATUSES = [ 'completed', 'failed', 'cancelled' ];

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
	const [ historyPage, setHistoryPage ] = useState( 1 );
	const [ historyTotalPages, setHistoryTotalPages ] = useState( 0 );
	const [ selectedHistoryJob, setSelectedHistoryJob ] =
		useState< JobStatus | null >( null );
	const [ historyDetails, setHistoryDetails ] = useState< SiteJobState[] >(
		[]
	);
	const [ historyDetailsLoading, setHistoryDetailsLoading ] =
		useState( false );
	const [ retryingHistoryJob, setRetryingHistoryJob ] = useState( false );
	const [ cancelling, setCancelling ] = useState( false );
	const intervalRef = useRef< ReturnType< typeof setInterval > | null >(
		null
	);
	const historyRetryIntervalRef = useRef< ReturnType<
		typeof setInterval
	> | null >( null );
	const siteStatesRef = useRef< SiteJobState[] >( [] );

	const entitySelectorsDisabled = saving || reindexing;

	const stopPolling = useCallback( () => {
		if ( intervalRef.current ) {
			clearInterval( intervalRef.current );
			intervalRef.current = null;
		}
	}, [] );

	const stopHistoryRetryPolling = useCallback( () => {
		if ( historyRetryIntervalRef.current ) {
			clearInterval( historyRetryIntervalRef.current );
			historyRetryIntervalRef.current = null;
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

	const fetchHistory = useCallback( async ( page: number = 1 ) => {
		try {
			const url = new URL(
				`${ API_NAMESPACE }/jobs/history`,
				window.location.origin
			);
			url.searchParams.set( 'page', String( page ) );
			url.searchParams.set( 'per_page', '5' );

			const res = await fetch( url.toString(), {
				headers: { 'X-WP-Nonce': NONCE },
			} );
			const data: HistoryResponse = await res.json();
			setHistory( data.jobs || [] );
			setHistoryPage( data.page || 1 );
			setHistoryTotalPages( data.total_pages || 0 );
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

		const allTerminal = updated.every( ( s ) => {
			if ( ! s.reindexJob ) {
				return true;
			}

			if ( ! TERMINAL_JOB_STATUSES.includes( s.reindexJob.status ) ) {
				return false;
			}

			const expectedChildCount =
				s.reindexJob.children_total ??
				s.reindexJob.child_ids?.length ??
				s.children.length;

			if ( expectedChildCount === 0 ) {
				return true;
			}

			if ( s.children.length < expectedChildCount ) {
				return false;
			}

			return s.children.every( ( child ) =>
				TERMINAL_JOB_STATUSES.includes( child.status )
			);
		} );
		if ( allTerminal ) {
			stopPolling();
			setReindexing( false );
			setSiteStates( [] );
			fetchHistory( 1 );
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
		return () => {
			stopPolling();
			stopHistoryRetryPolling();
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// On mount, check if a reindex was in progress before a page refresh.
	useEffect( () => {
		const restoreReindexState = async () => {
			try {
				const res = await fetch( `${ API_NAMESPACE }/re-index/status`, {
					headers: { 'X-WP-Nonce': NONCE },
				} );
				const data = await res.json();
				if ( data.success && data.active && data.jobs?.length ) {
					const initial: SiteJobState[] = data.jobs.map(
						( site: SiteJob ) => ( {
							site,
							reindexJob: null,
							children: [],
							expanded: false,
						} )
					);
					setReindexing( true );
					setShowReindexingModal( true );
					setSiteStates( initial );
					siteStatesRef.current = initial;
					const interval = setInterval( () => pollAllSites(), 2000 );
					intervalRef.current = interval;
					pollAllSites();
					fetchHistory( 1 );
				}
			} catch {
				// Silently ignore — the status endpoint is best-effort.
			}
		};
		restoreReindexState();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const retryJob = async ( jobId: string, siteUrl = currentSiteUrl ) => {
		try {
			const isLocalRetry = isCurrentSite( siteUrl );
			const res = isLocalRetry
				? await fetch(
						`${ API_NAMESPACE }/jobs/${ encodeURIComponent(
							jobId
						) }/retry`,
						{
							method: 'POST',
							headers: { 'X-WP-Nonce': NONCE },
						}
				  )
				: await fetch( `${ API_NAMESPACE }/jobs/remote-retry`, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': NONCE,
						},
						body: JSON.stringify( {
							site_url: siteUrl,
							job_id: jobId,
						} ),
				  } );
			const data = await res.json();
			return data;
		} catch {
			return {
				success: false,
				message: __( 'Retry request failed.', 'onesearch' ),
			};
		}
	};

	const getHistorySites = ( job: JobStatus ): SiteJob[] => {
		const sitesFromJob = job.data?.sites;
		if ( Array.isArray( sitesFromJob ) && sitesFromJob.length > 0 ) {
			return sitesFromJob;
		}

		return [
			{
				site_name: __( 'Governing Site', 'onesearch' ),
				site_url: currentSiteUrl,
				job_id: job.id,
				batch_count:
					job.children_total ||
					job.data?.total_batches ||
					job.progress_total ||
					0,
			},
		];
	};

	const fetchHistoryDetailsForJob = async (
		job: JobStatus
	): Promise< SiteJobState[] > =>
		Promise.all(
			getHistorySites( job ).map( async ( site ) => {
				if ( ! site.job_id ) {
					return {
						site,
						reindexJob: null,
						children: [],
						expanded: true,
					};
				}

				const result = isCurrentSite( site.site_url )
					? await fetchJobWithChildren( site.job_id )
					: await fetchRemoteJobStatus( site.site_url, site.job_id );

				return {
					site,
					reindexJob:
						result?.reindexJob ||
						( site.job_id === job.id ? job : null ),
					children: result?.children || [],
					expanded: true,
				};
			} )
		);

	const openHistoryDetails = async ( job: JobStatus ) => {
		setSelectedHistoryJob( job );
		setHistoryDetailsLoading( true );

		const siteDetails = await fetchHistoryDetailsForJob( job );

		setHistoryDetails( siteDetails );
		setHistoryDetailsLoading( false );
	};

	const hasFailedHistoryDetails = historyDetails.some(
		( state ) =>
			state.reindexJob?.status === 'failed' ||
			state.children.some( ( child ) => child.status === 'failed' )
	);

	const refreshHistoryRetryDetails = async ( job: JobStatus ) => {
		const siteDetails = await fetchHistoryDetailsForJob( job );
		setHistoryDetails( siteDetails );

		const updatedSelectedJob =
			siteDetails.find( ( state ) => state.site.job_id === job.id )
				?.reindexJob || job;
		setSelectedHistoryJob( updatedSelectedJob );

		const allTerminal = siteDetails.every( ( state ) => {
			if ( state.children.length > 0 ) {
				return state.children.every( ( child ) =>
					TERMINAL_JOB_STATUSES.includes( child.status )
				);
			}

			return state.reindexJob
				? TERMINAL_JOB_STATUSES.includes( state.reindexJob.status )
				: true;
		} );

		if ( allTerminal ) {
			stopHistoryRetryPolling();
			setRetryingHistoryJob( false );
			fetchHistory( historyPage );
		}
	};

	const handleRetryHistoryJob = async () => {
		if ( ! selectedHistoryJob || retryingHistoryJob ) {
			return;
		}

		const jobsToRetry = historyDetails.filter(
			( state ) =>
				state.site.job_id &&
				( state.reindexJob?.status === 'failed' ||
					state.children.some(
						( child ) => child.status === 'failed'
					) )
		);

		if ( jobsToRetry.length === 0 ) {
			return;
		}

		setRetryingHistoryJob( true );
		const results = await Promise.all(
			jobsToRetry.map( ( state ) =>
				retryJob( state.site.job_id, state.site.site_url )
			)
		);

		const failedResult = results.find( ( result ) => ! result.success );
		if ( failedResult ) {
			setRetryingHistoryJob( false );
			setNotice( {
				type: 'error',
				message:
					failedResult.message || __( 'Retry failed.', 'onesearch' ),
			} );
			return;
		}

		setNotice( {
			type: 'success',
			message: __( 'Failed batches retry scheduled.', 'onesearch' ),
		} );

		setHistoryDetails( ( prev ) =>
			prev.map( ( state ) => {
				if (
					! jobsToRetry.some(
						( job ) => job.site.job_id === state.site.job_id
					)
				) {
					return state;
				}

				return {
					...state,
					reindexJob: state.reindexJob
						? { ...state.reindexJob, status: 'running', error: '' }
						: state.reindexJob,
					children: state.children.map( ( child ) =>
						child.status === 'failed'
							? { ...child, status: 'pending', error: '' }
							: child
					),
				};
			} )
		);

		setSelectedHistoryJob( {
			...selectedHistoryJob,
			status: 'running',
			error: '',
			children_failed: 0,
		} );

		stopHistoryRetryPolling();
		historyRetryIntervalRef.current = setInterval( () => {
			void refreshHistoryRetryDetails( selectedHistoryJob );
		}, 2000 );
		void refreshHistoryRetryDetails( selectedHistoryJob );
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
			fetchHistory( 1 );

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
		setSelectedHistoryJob( null );
		setHistoryDetails( [] );
		setShowReindexingModal( false );
	};

	const handleHistoryDetailsBack = () => {
		setSelectedHistoryJob( null );
		setHistoryDetails( [] );
		setHistoryDetailsLoading( false );
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

		// Only cancel jobs on the current site; remote job IDs won't
		// exist on the local REST API.
		await Promise.all(
			activeJobs
				.filter( ( s ) => isCurrentSite( s.site.site_url ) )
				.map( ( s ) =>
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
		fetchHistory( 1 );
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
		</div>
	);

	const renderSiteRow = ( state: SiteJobState ) => {
		const hasChildren = state.children.length > 0;
		const canExpand =
			hasChildren ||
			( !! state.reindexJob &&
				state.reindexJob.status !== 'completed' &&
				state.reindexJob.status !== 'cancelled' );
		const handleKeyDown = ( e: React.KeyboardEvent ) => {
			if ( ( e.key === 'Enter' || e.key === ' ' ) && canExpand ) {
				e.preventDefault();
				toggleExpand( state.site.site_url );
			}
		};

		return (
			<div key={ state.site.site_url } className="onesearch-job-site-row">
				<div
					className="onesearch-job-site-header"
					onClick={ () =>
						canExpand ? toggleExpand( state.site.site_url ) : null
					}
					onKeyDown={ handleKeyDown }
					role={ canExpand ? 'button' : undefined }
					aria-expanded={ canExpand ? state.expanded : undefined }
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

	const formatDuration = ( seconds: number ): string => {
		if ( seconds < 60 ) {
			return sprintf(
				/* translators: %d: seconds */
				__( '%ds', 'onesearch' ),
				seconds
			);
		}
		const mins = Math.floor( seconds / 60 );
		const secs = seconds % 60;
		if ( mins < 60 ) {
			return sprintf(
				/* translators: 1: minutes, 2: seconds */
				__( '%1$dm %2$ds', 'onesearch' ),
				mins,
				secs
			);
		}
		const hrs = Math.floor( mins / 60 );
		const remainingMins = mins % 60;
		return sprintf(
			/* translators: 1: hours, 2: minutes */
			__( '%1$dh %2$dm', 'onesearch' ),
			hrs,
			remainingMins
		);
	};

	const formatTimestamp = ( ts?: number ): string =>
		ts ? new Date( ts * 1000 ).toLocaleString() : '—';

	const renderHistoryDetailsView = () => {
		if ( ! selectedHistoryJob ) {
			return null;
		}

		const historySites = getHistorySites( selectedHistoryJob );
		const totalBatches = historySites.reduce(
			( sum, site ) => sum + ( site.batch_count || 0 ),
			0
		);

		return (
			<div className="onesearch-history-details-view">
				<div className="onesearch-history-details-toolbar">
					<Button
						variant="tertiary"
						size="small"
						onClick={ handleHistoryDetailsBack }
						className="onesearch-history-details-back"
					>
						‹ { __( 'Back', 'onesearch' ) }
					</Button>
					{ ( hasFailedHistoryDetails || retryingHistoryJob ) && (
						<Button
							variant="primary"
							size="small"
							onClick={ () => void handleRetryHistoryJob() }
							isBusy={ retryingHistoryJob }
							disabled={ retryingHistoryJob }
						>
							{ retryingHistoryJob
								? __( 'Retrying…', 'onesearch' )
								: __( 'Retry Failed Batches', 'onesearch' ) }
						</Button>
					) }
				</div>

				<div className="onesearch-history-details-summary">
					<div>
						<span>{ __( 'Sites', 'onesearch' ) }</span>
						<strong>{ historySites.length }</strong>
					</div>
					<div>
						<span>{ __( 'Batches', 'onesearch' ) }</span>
						<strong>
							{ totalBatches ||
								selectedHistoryJob.children_total ||
								selectedHistoryJob.progress_total }
						</strong>
					</div>
					<div>
						<span>{ __( 'Status', 'onesearch' ) }</span>
						{ renderJobStatusBadge(
							selectedHistoryJob.status,
							'small'
						) }
					</div>
				</div>

				{ selectedHistoryJob.error && (
					<div className="onesearch-job-error">
						{ selectedHistoryJob.error }
					</div>
				) }

				<div
					className={ `onesearch-history-details-content${
						historyDetailsLoading
							? ' onesearch-history-details-content--loading'
							: ''
					}` }
				>
					{ historyDetailsLoading && (
						<div className="onesearch-history-details-loading">
							<span className="onesearch-reindex-spinner" />
							<Text variant="muted">
								{ __( 'Loading job details…', 'onesearch' ) }
							</Text>
						</div>
					) }

					{ ! historyDetailsLoading && (
						<div className="onesearch-history-details-sites">
							{ historyDetails.map( ( state ) => (
								<div
									key={ state.site.site_url }
									className="onesearch-history-details-site"
								>
									<div className="onesearch-history-details-site-header">
										<div>
											<strong>
												{ state.site.site_name }
											</strong>
											<Text variant="muted">
												{ state.site.site_url }
											</Text>
										</div>
										<div className="onesearch-history-details-site-meta">
											<Text variant="muted">
												{ sprintf(
													/* translators: %d: batch count */
													__(
														'%d batches',
														'onesearch'
													),
													state.site.batch_count ||
														state.children.length
												) }
											</Text>
											{ state.reindexJob &&
												renderJobStatusBadge(
													state.reindexJob.status,
													'small'
												) }
										</div>
									</div>

									{ state.children.length > 0 ? (
										<div className="onesearch-batch-list">
											{ state.children.map(
												( child, idx ) =>
													renderBatchRow( child, idx )
											) }
										</div>
									) : (
										<Text
											variant="muted"
											className="onesearch-history-details-empty"
										>
											{ __(
												'No batch details available.',
												'onesearch'
											) }
										</Text>
									) }
								</div>
							) ) }
						</div>
					) }
				</div>
			</div>
		);
	};

	const renderHistoryTable = () => {
		if ( history.length === 0 ) {
			return (
				<Text variant="muted">
					{ __( 'No past jobs.', 'onesearch' ) }
				</Text>
			);
		}

		return (
			<table className="onesearch-history-table">
				<thead>
					<tr>
						<th>{ __( 'ID', 'onesearch' ) }</th>
						<th>{ __( 'Type', 'onesearch' ) }</th>
						<th>{ __( 'Created at', 'onesearch' ) }</th>
						<th>{ __( 'Duration', 'onesearch' ) }</th>
						<th>{ __( 'Status', 'onesearch' ) }</th>
						<th>{ __( 'Batches', 'onesearch' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ history.map( ( job ) => {
						const totalBatches =
							( job.data?.[ 'total_batches' ] as number ) ||
							job.children_total ||
							job.progress_total;
						const completedBatches =
							job.children_completed ?? job.progress;
						const hasBatches = totalBatches > 0;
						const batchDisplay = hasBatches
							? `${ completedBatches }/${ totalBatches }`
							: '—';
						const duration =
							job.finished_at && job.created_at
								? job.finished_at - job.created_at
								: null;

						const handleHistoryRowKeyDown = (
							e: React.KeyboardEvent
						) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								void openHistoryDetails( job );
							}
						};

						return (
							<tr
								key={ job.id }
								className="onesearch-history-table-row"
								onClick={ () => void openHistoryDetails( job ) }
								onKeyDown={ handleHistoryRowKeyDown }
								role="button"
								tabIndex={ 0 }
							>
								<td>
									<code title={ job.id }>
										{ job.id.substring( 0, 16 ) }…
									</code>
								</td>
								<td>
									<Text variant="muted">
										{ job.group || 'reindex' }
									</Text>
								</td>
								<td>
									<Text variant="muted">
										{ formatTimestamp( job.created_at ) }
									</Text>
								</td>
								<td>
									<Text variant="muted">
										{ duration !== null
											? formatDuration( duration )
											: '—' }
									</Text>
								</td>
								<td>
									{ renderJobStatusBadge(
										job.status,
										'small'
									) }
								</td>
								<td>
									<Text variant="muted">
										{ batchDisplay }
									</Text>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		);
	};

	const renderHistoryPagination = () => {
		if ( historyTotalPages <= 1 ) {
			return null;
		}

		const pages: ( number | string )[] = [];
		const delta = 2;
		const last = historyTotalPages;

		// Always include page 1.
		pages.push( 1 );

		// Calculate range around current page.
		const rangeStart = Math.max( 2, historyPage - delta );
		const rangeEnd = Math.min( last - 1, historyPage + delta );

		// Add ellipsis + range pages.
		if ( rangeStart > 2 ) {
			pages.push( '…' );
		}
		for ( let i = rangeStart; i <= rangeEnd; i++ ) {
			pages.push( i );
		}
		if ( rangeEnd < last - 1 ) {
			pages.push( '…' );
		}

		// Always include last page (unless last === 1).
		if ( last > 1 ) {
			pages.push( last );
		}

		const handlePageClick = ( page: number ) => {
			if ( page === historyPage || page < 1 || page > last ) {
				return;
			}
			fetchHistory( page );
		};

		return (
			<div className="onesearch-history-pagination">
				<Button
					variant="tertiary"
					size="small"
					disabled={ historyPage <= 1 }
					onClick={ () => handlePageClick( historyPage - 1 ) }
					aria-label={ __( 'Previous page', 'onesearch' ) }
				>
					‹
				</Button>
				{ pages.map( ( p, idx ) => {
					if ( typeof p === 'string' ) {
						return (
							<span
								key={ `ellipsis-${ idx }` }
								className="onesearch-history-pagination-ellipsis"
							>
								…
							</span>
						);
					}
					return (
						<Button
							key={ p }
							variant={
								p === historyPage ? 'primary' : 'tertiary'
							}
							size="small"
							onClick={ () => handlePageClick( p ) }
							className={
								p === historyPage
									? 'onesearch-history-pagination-current'
									: ''
							}
						>
							{ p }
						</Button>
					);
				} ) }
				<Button
					variant="tertiary"
					size="small"
					disabled={ historyPage >= last }
					onClick={ () => handlePageClick( historyPage + 1 ) }
					aria-label={ __( 'Next page', 'onesearch' ) }
				>
					›
				</Button>
			</div>
		);
	};

	const getReindexModalTitle = () => {
		if ( selectedHistoryJob ) {
			return __( 'Sync Job Details', 'onesearch' );
		}

		if ( reindexing ) {
			return __( 'Indexing Progress', 'onesearch' );
		}

		return __( 'Re-index saved entities', 'onesearch' );
	};

	const renderReindexModalContent = () => (
		<>
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

			{ reindexing && siteStates.length === 0 && (
				<div className="onesearch-reindex-loading">
					<span className="onesearch-reindex-spinner" />
					<p className="onesearch-reindex-loading-text">
						{ __(
							'Preparing entities for re-indexing. This may take a moment.',
							'onesearch'
						) }
					</p>
				</div>
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
						<span className="onesearch-job-panel-total">
							{ ( () => {
								const totalBatches = siteStates.reduce(
									( sum, s ) => sum + s.children.length,
									0
								);
								const totalDone = siteStates.reduce(
									( sum, s ) =>
										sum +
										s.children.filter( ( c ) =>
											[ 'completed', 'failed' ].includes(
												c.status
											)
										).length,
									0
								);
								if ( totalBatches > 0 ) {
									return sprintf(
										/* translators: 1: done batches, 2: total batches */
										__(
											'%1$d / %2$d batches',
											'onesearch'
										),
										totalDone,
										totalBatches
									);
								}
								return '';
							} )() }
						</span>
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
						{ siteStates.map( ( s ) => renderSiteRow( s ) ) }
					</div>
				</div>
			) }

			<div className="onesearch-job-history">
				<h3 className="onesearch-job-history-header">
					{ __( 'Sync Job History', 'onesearch' ) }
				</h3>
				<div className="onesearch-job-panel-body">
					{ renderHistoryTable() }
					{ renderHistoryPagination() }
				</div>
			</div>
		</>
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
											fetchHistory( 1 );
										}
										return ! prev;
									} );
								} }
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
					title={ getReindexModalTitle() }
					onRequestClose={ handleModalClose }
					shouldCloseOnClickOutside={ false }
					size="large"
				>
					{ selectedHistoryJob
						? renderHistoryDetailsView()
						: renderReindexModalContent() }
				</Modal>
			) }
		</>
	);
};

export default SiteIndexableEntities;
