/**
 * External dependencies
 */
import { useCallback, useEffect, useRef, useState } from 'react';
/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import type { NoticeType } from '@/admin/settings/page';
import { API_NAMESPACE, NONCE, withTrailingSlash } from '@/js/utils';
/**
 * Internal dependencies
 */
import { TERMINAL_JOB_STATUSES } from './constants';
import type {
	HistoryResponse,
	JobStatus,
	ReIndexResponse,
	SiteJob,
	SiteJobState,
} from './types';
import { getHistorySites } from './utils';

interface UseReindexJobParams {
	currentSiteUrl: string;
	setNotice: ( notice: NoticeType | null ) => void;
}

export interface UseReindexJobReturn {
	reindexing: boolean;
	showReindexingModal: boolean;
	setShowReindexingModal: React.Dispatch< React.SetStateAction< boolean > >;
	siteStates: SiteJobState[];
	cancelling: boolean;
	history: JobStatus[];
	historyPage: number;
	historyTotalPages: number;
	selectedHistoryJob: JobStatus | null;
	historyDetails: SiteJobState[];
	historyDetailsLoading: boolean;
	hasFailedHistoryDetails: boolean;
	retryingHistoryJob: boolean;
	handleReIndex: () => Promise< boolean >;
	handleCancelJob: () => Promise< void >;
	handleModalClose: () => void;
	handleHistoryDetailsBack: () => void;
	handleRetryHistoryJob: () => Promise< void >;
	openHistoryDetails: ( job: JobStatus ) => Promise< void >;
	toggleExpand: ( siteUrl: string ) => void;
	fetchHistory: ( page?: number ) => Promise< void >;
}

export const useReindexJob = ( {
	currentSiteUrl,
	setNotice,
}: UseReindexJobParams ): UseReindexJobReturn => {
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
	const selectedHistoryJobRef = useRef< JobStatus | null >( null );

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

	const isCurrentSite = useCallback(
		( siteUrl: string ) => withTrailingSlash( siteUrl ) === currentSiteUrl,
		[ currentSiteUrl ]
	);

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
			if ( TERMINAL_JOB_STATUSES.includes( s.reindexJob.status ) ) {
				return true;
			}
			if ( s.children.length > 0 ) {
				return s.children.every( ( c ) =>
					TERMINAL_JOB_STATUSES.includes( c.status )
				);
			}
			return false;
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
		selectedHistoryJobRef.current = selectedHistoryJob;
	}, [ selectedHistoryJob ] );

	useEffect( () => {
		return () => {
			stopPolling();
			stopHistoryRetryPolling();
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// On mount, resume any reindex that was running before a page refresh.
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
			return await res.json();
		} catch {
			return {
				success: false,
				message: __( 'Retry request failed.', 'onesearch' ),
			};
		}
	};

	const fetchHistoryDetailsForJob = async (
		job: JobStatus
	): Promise< SiteJobState[] > =>
		Promise.all(
			getHistorySites( job, currentSiteUrl ).map( async ( site ) => {
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
			void refreshHistoryRetryDetails(
				selectedHistoryJobRef.current ?? selectedHistoryJob
			);
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
				const interval = setInterval( () => pollAllSites(), 2000 );
				intervalRef.current = interval;
				pollAllSites();
				return true;
			}

			const errorMsg =
				data.message || __( 'Unknown error.', 'onesearch' );
			setReindexing( false );
			setShowReindexingModal( false );
			setNotice( { message: errorMsg, type: 'error' } );
		} catch ( error: unknown ) {
			const message =
				error instanceof Error ? error.message : String( error );
			setReindexing( false );
			setShowReindexingModal( false );
			setNotice( { message, type: 'error' } );
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
				s.site.job_id &&
				( ! s.reindexJob ||
					! [ 'completed', 'failed', 'cancelled' ].includes(
						s.reindexJob.status
					) )
		);

		await Promise.all(
			activeJobs.map( ( s ) => {
				if ( isCurrentSite( s.site.site_url ) ) {
					return fetch(
						`${ API_NAMESPACE }/jobs/${ encodeURIComponent(
							s.site.job_id
						) }`,
						{
							method: 'DELETE',
							headers: { 'X-WP-Nonce': NONCE },
						}
					).catch( () => {} );
				}
				return fetch( `${ API_NAMESPACE }/jobs/remote-cancel`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE,
					},
					body: JSON.stringify( {
						site_url: s.site.site_url,
						job_id: s.site.job_id,
					} ),
				} ).catch( () => {} );
			} )
		);

		stopPolling();
		setReindexing( false );
		setSiteStates( [] );
		setCancelling( false );
		fetchHistory( 1 );
	};

	return {
		reindexing,
		showReindexingModal,
		setShowReindexingModal,
		siteStates,
		cancelling,
		history,
		historyPage,
		historyTotalPages,
		selectedHistoryJob,
		historyDetails,
		historyDetailsLoading,
		hasFailedHistoryDetails,
		retryingHistoryJob,
		handleReIndex,
		handleCancelJob,
		handleModalClose,
		handleHistoryDetailsBack,
		handleRetryHistoryJob,
		openHistoryDetails,
		toggleExpand,
		fetchHistory,
	};
};
