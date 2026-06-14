import { __ } from '@wordpress/i18n';

const STATUS_STYLES = {
  active: {
    backgroundColor: '#eafaf0',
    color: '#1a7338',
    label: __( 'Active', 'mission-donation-platform' ),
  },
  pending: {
    backgroundColor: '#fef5e7',
    color: '#a06000',
    label: __( 'Pending', 'mission-donation-platform' ),
  },
  inactive: {
    backgroundColor: '#f0f0f5',
    color: '#6b6b7b',
    label: __( 'Inactive', 'mission-donation-platform' ),
  },
};

/**
 * Status pill for fundraisers and teams (active / pending / inactive).
 *
 * @param {Object} props        Component props.
 * @param {string} props.status The status value.
 * @return {JSX.Element} The badge.
 */
export default function P2PStatusBadge( { status } ) {
  const style = STATUS_STYLES[ status ] || STATUS_STYLES.pending;

  return (
    <span
      style={ {
        display: 'inline-block',
        padding: '2px 8px',
        borderRadius: '2px',
        fontSize: '12px',
        fontWeight: 500,
        backgroundColor: style.backgroundColor,
        color: style.color,
      } }
    >
      { style.label }
    </span>
  );
}
