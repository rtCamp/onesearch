/**
 * External dependencies
 */
import type { StatusUIType } from '@/types/global';
/**
 * Internal dependencies
 */
import { STATUS_LABELS } from './constants';

interface JobStatusBadgeProps {
	status: string;
	size?: 'normal' | 'small';
	type?: StatusUIType;
}

const JobStatusBadge = ( {
	status,
	size = 'normal',
	type = 'badge',
}: JobStatusBadgeProps ) => {
	if ( type === 'text' ) {
		return <strong>{ STATUS_LABELS[ status ] || status }</strong>;
	}
	return (
		<span
			className={ `onesearch-job-status onesearch-job-status--${ status }${
				size === 'small' ? ' onesearch-job-status--small' : ''
			}` }
		>
			{ STATUS_LABELS[ status ] || status }
		</span>
	);
};

export default JobStatusBadge;
