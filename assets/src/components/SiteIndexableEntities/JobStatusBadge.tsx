/**
 * Internal dependencies
 */
import { STATUS_LABELS } from './constants';

interface JobStatusBadgeProps {
	status: string;
	size?: 'normal' | 'small';
}

const JobStatusBadge = ( { status, size = 'normal' }: JobStatusBadgeProps ) => {
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
