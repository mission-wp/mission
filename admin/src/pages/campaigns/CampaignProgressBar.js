import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';

const BRAND_COLOR = '#2FA36B';

export default function CampaignProgressBar( {
  raised,
  goal,
  goalType = 'amount',
} ) {
  const percentage =
    goal > 0 ? Math.min( Math.round( ( raised / goal ) * 100 ), 100 ) : 0;

  const of = __( 'of', 'mission' );
  let label;
  if ( goalType === 'amount' ) {
    label = `${ formatAmount( raised ) } ${ of } ${ formatAmount(
      goal
    ) } (${ percentage }%)`;
  } else {
    const unit =
      goalType === 'donors'
        ? __( 'donors', 'mission' )
        : __( 'donations', 'mission' );
    label = `${ raised.toLocaleString() } ${ of } ${ goal.toLocaleString() } ${ unit } (${ percentage }%)`;
  }

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
        { label }
      </span>
    </div>
  );
}
