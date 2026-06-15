interface ProgressBarProps {
	percent: number;
}

const ProgressBar = ( { percent }: ProgressBarProps ) => (
	<div className="onesearch-job-progress-bar onesearch-job-progress-bar--small">
		<div
			className="onesearch-job-progress-fill"
			style={ {
				width: `${ Math.max( 0, Math.min( 100, percent ) ) }%`,
			} }
		/>
	</div>
);

export default ProgressBar;
