import { __ } from '@wordpress/i18n';
import { STATUS_BADGE_LABELS } from '../constants';

/**
 * Status pill shared across list and detail screens.
 *
 * The badge class is `is-{tone}` when a tone is given, otherwise `is-{status}`.
 * Tones let domains restyle an overloaded status value (e.g. P2P "pending"
 * renders amber via tone "warning" while transaction "pending" stays blue).
 *
 * @param {Object} props        Component props.
 * @param {string} props.status The status value.
 * @param {string} props.label  Optional label override.
 * @param {string} props.tone   Optional visual tone overriding the status class.
 * @return {JSX.Element} The badge.
 */
export default function StatusBadge( { status, label, tone } ) {
  const text =
    label ||
    STATUS_BADGE_LABELS[ status ] ||
    ( status
      ? status.charAt( 0 ).toUpperCase() + status.slice( 1 )
      : __( 'Pending', 'mission-donation-platform' ) );

  return (
    <span
      className={ `mission-status-badge is-${ tone || status || 'pending' }` }
    >
      { text }
    </span>
  );
}
