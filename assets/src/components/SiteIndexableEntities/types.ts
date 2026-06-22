export interface EntitiesMap {
	[ siteUrl: string ]: string[];
}

export interface IndexableEntitiesResponse {
	indexableEntities: {
		entities: EntitiesMap;
	};
}

export interface SaveEntitiesResponse {
	success: boolean;
	message?: string;
	data?: {
		status?: number;
	};
}

export interface SiteJob {
	site_name: string;
	site_url: string;
	job_id: string;
	batch_count?: number;
}

export interface ReIndexResponse {
	success: boolean;
	message?: string;
	job_id?: string;
	batch_count?: number;
	jobs?: SiteJob[];
	data?: {
		status?: number;
	};
}

export interface HistoryResponse {
	success: boolean;
	jobs: JobStatus[];
	total: number;
	page: number;
	per_page: number;
	total_pages: number;
}

export interface JobStatus {
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

export interface SiteJobState {
	site: SiteJob;
	reindexJob: JobStatus | null;
	children: JobStatus[];
	expanded: boolean;
}
