/**
 * Edit component for the Team Progress block.
 *
 * The block resolves its team from the page it sits on (the team template), so
 * the editor shows a representative sample preview. Shares the flat progress
 * look with the Campaign and Fundraiser Progress blocks.
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __, sprintf } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { computePrimaryColorVars } from '@shared/color';
import { formatAmount } from '@shared/currency';

const SAMPLE = {
  raised: 842000,
  goal: 1000000,
  members: 12,
  donations: 96,
};

export default function Edit() {
  const primaryColorVars = useMemo( () => {
    const color = window.missiondpBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

  const percentage = Math.round( ( SAMPLE.raised / SAMPLE.goal ) * 100 );

  return (
    <div { ...useBlockProps() }>
      <div className="mission-progress" style={ primaryColorVars }>
        <div className="mission-progress__header">
          <span className="mission-progress__raised">
            { formatAmount( SAMPLE.raised ) }
          </span>
          <span className="mission-progress__goal">
            { sprintf(
              /* translators: %s: formatted goal amount */
              __( 'raised of %s team goal', 'mission-donation-platform' ),
              formatAmount( SAMPLE.goal )
            ) }
          </span>
          <span className="mission-progress__percentage">
            { percentage + '%' }
          </span>
        </div>
        <div className="mission-progress__bar">
          <div
            className="mission-progress__bar-fill"
            style={ { '--bar-width': percentage + '%' } }
          />
        </div>
        <div className="mission-progress__stats">
          <div className="mission-progress__stat">
            <span className="mission-progress__stat-value">
              { SAMPLE.members }
            </span>
            <span className="mission-progress__stat-label">
              { __( 'members', 'mission-donation-platform' ) }
            </span>
          </div>
          <div className="mission-progress__stat">
            <span className="mission-progress__stat-value">
              { SAMPLE.donations }
            </span>
            <span className="mission-progress__stat-label">
              { __( 'donations', 'mission-donation-platform' ) }
            </span>
          </div>
          <div className="mission-progress__stat">
            <span className="mission-progress__stat-value">
              { formatAmount( SAMPLE.raised ) }
            </span>
            <span className="mission-progress__stat-label">
              { __( 'raised', 'mission-donation-platform' ) }
            </span>
          </div>
        </div>
        <div className="mission-progress__actions">
          <span className="mission-progress__btn">
            { __( 'Donate to the Team', 'mission-donation-platform' ) }
          </span>
          <span className="mission-progress__btn mission-progress__btn--secondary">
            { __( 'Join this Team', 'mission-donation-platform' ) }
          </span>
        </div>
      </div>
    </div>
  );
}
