/**
 * Team Members block — editor registration.
 *
 * The block resolves its team from the page it sits on (the team template), so
 * the editor shows a self-describing placeholder list rather than real data.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __, sprintf } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { computePrimaryColorVars } from '@shared/color';
import { formatAmount } from '@shared/currency';
import { PersonIcon } from '@shared/PersonIcon';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

// Placeholder rows showing the ranked-list layout only — no real members exist
// while editing.
const ROWS = [
  { captain: true, raised: 168000, goal: 200000 },
  { captain: false, raised: 92000, goal: 200000 },
  { captain: false, raised: 50000, goal: 200000 },
];

function Edit() {
  const primaryColorVars = useMemo( () => {
    const color = window.missiondpBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

  return (
    <div
      { ...useBlockProps( { className: 'mission-tm' } ) }
      style={ primaryColorVars }
    >
      <p className="mission-tm__sample-notice">
        { __( 'Preview uses sample data.', 'mission-donation-platform' ) }
      </p>
      <ul className="mission-tm__list">
        { ROWS.map( ( row, index ) => {
          const percentage = Math.round( ( row.raised / row.goal ) * 100 );
          return (
            <li className="mission-tm__item" key={ index }>
              <span className="mission-tm__rank">{ index + 1 }</span>
              <div className="mission-tm__avatar">
                <PersonIcon size={ 20 } />
              </div>
              <div className="mission-tm__info">
                <div className="mission-tm__name">
                  { row.captain
                    ? __( 'Team captain', 'mission-donation-platform' )
                    : __( 'Member name', 'mission-donation-platform' ) }
                  { row.captain && (
                    <span className="mission-tm__badge">
                      { __( 'Captain', 'mission-donation-platform' ) }
                    </span>
                  ) }
                </div>
                <div className="mission-tm__bar">
                  <div
                    className="mission-tm__bar-fill"
                    style={ { '--bar-width': percentage + '%' } }
                  />
                </div>
              </div>
              <div className="mission-tm__amount">
                <div className="mission-tm__amount-value">
                  { formatAmount( row.raised, undefined, {
                    stripZeroCents: true,
                  } ) }
                </div>
                <div className="mission-tm__amount-label">
                  { sprintf(
                    /* translators: %s: formatted goal amount */
                    __( 'of %s', 'mission-donation-platform' ),
                    formatAmount( row.goal, undefined, {
                      stripZeroCents: true,
                    } )
                  ) }
                </div>
              </div>
            </li>
          );
        } ) }
      </ul>
    </div>
  );
}

registerBlockType( metadata, { edit: Edit } );
