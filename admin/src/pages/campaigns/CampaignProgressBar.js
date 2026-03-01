const BRAND_COLOR = '#2FA36B';

export default function CampaignProgressBar( { raised, goal } ) {
	const percentage =
		goal > 0 ? Math.min( Math.round( ( raised / goal ) * 100 ), 100 ) : 0;

	return (
		<div style={ { width: '100%' } }>
			<div
				style={ {
					height: '12px',
					backgroundColor: '#f0f0f0',
					borderRadius: '6px',
					overflow: 'hidden',
				} }
			>
				<div
					style={ {
						width: `${ percentage }%`,
						height: '100%',
						backgroundColor: BRAND_COLOR,
						borderRadius: '6px',
						transition: 'width 0.3s ease',
					} }
				/>
			</div>
			<span
				style={ {
					fontSize: '13px',
					color: '#757575',
					marginTop: '4px',
					display: 'inline-block',
				} }
			>
				{ `${ percentage }% of goal` }
			</span>
		</div>
	);
}
