/**
 * Single campaign card preview for the Campaign Grid editor.
 */
import { __ } from '@wordpress/i18n';
import {
  getDaysRemaining,
  getTag,
  getProgressDisplay,
  getTimeText,
} from '@shared/campaign-card-helpers';

export default function CardPreview( { campaign, attributes } ) {
  const {
    showImage,
    showTag,
    showDescription,
    showProgressBar,
    showDonorCount,
    buttonText,
  } = attributes;

  const daysRemaining = getDaysRemaining( campaign.date_end );
  const tag = showTag ? getTag( campaign, daysRemaining ) : null;
  const hasGoal = campaign.goal_amount > 0;
  const { raisedText, goalText, percentage } = getProgressDisplay( campaign );
  const barPercent = hasGoal ? Math.min( 100, percentage ) : 0;
  const timeText = getTimeText( campaign, daysRemaining );
  const isEnded = campaign.status === 'ended';
  const cardClasses = `mission-cc${ isEnded ? ' mission-cc--ended' : '' }`;

  const imageUrl =
    campaign.image_urls?.large ||
    campaign.image_urls?.medium ||
    campaign.image_urls?.full;

  return (
    <div className={ cardClasses }>
      { showImage && (
        <span className="mission-cc-image-wrap">
          { tag && (
            <span className={ `mission-cc-tag ${ tag.className }` }>
              { tag.text }
            </span>
          ) }
          { imageUrl ? (
            <img src={ imageUrl } alt={ campaign.title } />
          ) : (
            <span className="mission-cc-placeholder" />
          ) }
        </span>
      ) }
      <div className="mission-cc-body">
        <h3 className="mission-cc-title">{ campaign.title }</h3>
        { showDescription && campaign.description && (
          <p className="mission-cc-description">{ campaign.description }</p>
        ) }
        { showProgressBar && hasGoal && (
          <div className="mission-cc-progress">
            <div className="mission-cc-progress-header">
              <span className="mission-cc-raised">{ raisedText }</span>
              { goalText && (
                <span className="mission-cc-goal">{ goalText }</span>
              ) }
            </div>
            <div className="mission-cc-bar">
              <div
                className="mission-cc-bar__fill"
                style={ { '--bar-width': barPercent + '%' } }
              />
            </div>
            <div className="mission-cc-meta">
              { showDonorCount && (
                <span className="mission-cc-donors">
                  <strong>
                    { ( campaign.donor_count || 0 ).toLocaleString() }
                  </strong>{ ' ' }
                  { __( 'donors', 'missionwp-donation-platform' ) }
                </span>
              ) }
              <span className="mission-cc-time">{ timeText }</span>
            </div>
          </div>
        ) }
      </div>
      <div className="mission-cc-footer">
        <span className="mission-cc-btn">
          { buttonText || __( 'View Campaign', 'missionwp-donation-platform' ) }
        </span>
      </div>
    </div>
  );
}
