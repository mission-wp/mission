import { __ } from '@wordpress/i18n';
import { formatAmount } from '@shared/currency';
import DonorAvatar from '../../components/DonorAvatar';

export default function TransactionDonorCard( { donor } ) {
  const adminUrl = window.missionAdmin?.adminUrl || '';

  if ( ! donor ) {
    return (
      <div className="mission-card" style={ { padding: 0 } }>
        <h2 className="mission-card__heading">
          { __( 'Donor', 'missionwp-donation-platform' ) }
        </h2>
        <p style={ { textAlign: 'center', color: '#9b9ba8', padding: '16px' } }>
          { __( 'Anonymous or deleted donor', 'missionwp-donation-platform' ) }
        </p>
      </div>
    );
  }

  const fullName =
    [ donor.first_name, donor.last_name ].filter( Boolean ).join( ' ' ) ||
    __( 'Anonymous', 'missionwp-donation-platform' );

  const profileUrl = `${ adminUrl }admin.php?page=mission-donors&donor_id=${ donor.id }`;

  return (
    <div className="mission-card" style={ { padding: 0 } }>
      <div
        className="mission-card__heading"
        style={ {
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
        } }
      >
        <span>{ __( 'Donor', 'missionwp-donation-platform' ) }</span>
        <a
          href={ profileUrl }
          className="mission-table-link"
          style={ { fontSize: '13px', fontWeight: 400 } }
        >
          { __( 'View profile', 'missionwp-donation-platform' ) }
        </a>
      </div>
      <div
        style={ {
          display: 'flex',
          alignItems: 'center',
          gap: '12px',
          padding: '16px',
        } }
      >
        <DonorAvatar
          firstName={ donor.first_name }
          lastName={ donor.last_name }
          gravatarHash={ donor.gravatar_hash }
          size="lg"
        />
        <div style={ { flex: 1, minWidth: 0 } }>
          <div
            style={ { fontWeight: 500, fontSize: '14px', color: '#1a1a2e' } }
          >
            { fullName }
          </div>
          <div style={ { fontSize: '12px', color: '#9b9ba8' } }>
            { donor.email }
          </div>
        </div>
        <div
          style={ {
            fontSize: '13px',
            color: '#6b6b7b',
            flexShrink: 0,
            textAlign: 'right',
            lineHeight: 1.5,
          } }
        >
          <div>
            <strong style={ { color: '#1a1a2e' } }>
              { formatAmount( donor.total_donated ) }
            </strong>{ ' ' }
            { __( 'lifetime', 'missionwp-donation-platform' ) }
          </div>
          <div>
            <strong style={ { color: '#1a1a2e' } }>
              { donor.transaction_count }
            </strong>{ ' ' }
            { donor.transaction_count === 1
              ? __( 'donation', 'missionwp-donation-platform' )
              : __( 'donations', 'missionwp-donation-platform' ) }
          </div>
        </div>
      </div>
    </div>
  );
}
