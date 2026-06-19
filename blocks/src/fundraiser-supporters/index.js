/**
 * Fundraiser Supporters block — editor registration.
 *
 * The block resolves its fundraiser from the page it sits on (the fundraiser
 * template), so the editor shows a self-describing placeholder list rather than
 * real data.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { computePrimaryColorVars } from '@shared/color';
import { formatAmount } from '@shared/currency';
import { PersonIcon } from '@shared/PersonIcon';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

// Placeholder rows showing the list layout only — no real supporters exist
// while editing.
const ROWS = [
  { amount: 10000, showComment: true },
  { amount: 5000, showComment: false },
  { amount: 2500, showComment: false },
];

function Edit() {
  const primaryColorVars = useMemo( () => {
    const color = window.missiondpBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

  return (
    <div
      { ...useBlockProps( { className: 'mission-supporters' } ) }
      style={ primaryColorVars }
    >
      <p className="mission-supporters__sample-notice">
        { __( 'Preview uses sample data.', 'mission-donation-platform' ) }
      </p>
      <ul className="mission-donor-list">
        { ROWS.map( ( row, index ) => (
          <li className="mission-donor-item" key={ index }>
            <div className="mission-donor-item-left">
              <span className="mission-donor-avatar">
                <PersonIcon />
              </span>
              <div className="mission-donor-info">
                <span className="mission-donor-name">
                  { __( 'Supporter name', 'mission-donation-platform' ) }
                </span>
                { row.showComment && (
                  <span className="mission-donor-dedication">
                    { __(
                      'A note of support appears here.',
                      'mission-donation-platform'
                    ) }
                  </span>
                ) }
                <span className="mission-supporters__time">
                  { __( 'Just now', 'mission-donation-platform' ) }
                </span>
              </div>
            </div>
            <span className="mission-donor-amount">
              { formatAmount( row.amount, undefined, {
                stripZeroCents: true,
              } ) }
            </span>
          </li>
        ) ) }
      </ul>
    </div>
  );
}

registerBlockType( metadata, { edit: Edit } );
