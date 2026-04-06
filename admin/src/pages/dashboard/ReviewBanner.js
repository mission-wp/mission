import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { formatAmount } from '@shared/currency';

const STAR_PATH =
  'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z';
const REVIEW_URL =
  'https://wordpress.org/support/plugin/mission/reviews/#new-post';

function Star( { filled, onMouseEnter, onClick } ) {
  return (
    <button
      type="button"
      className={
        'mission-review-banner__star' + ( filled ? ' is-filled' : '' )
      }
      onMouseEnter={ onMouseEnter }
      onClick={ onClick }
    >
      <svg
        width="22"
        height="22"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
      >
        <path d={ STAR_PATH } />
      </svg>
    </button>
  );
}

export default function ReviewBanner( { totalRaised } ) {
  const [ hoveredStar, setHoveredStar ] = useState( 0 );
  const [ isHovering, setIsHovering ] = useState( false );
  // 'default' | 'thankyou' | 'hidden'
  const [ state, setState ] = useState( 'default' );

  const dismiss = useCallback( () => {
    setState( 'hidden' );
    apiFetch( {
      path: '/mission/v1/review-banner/dismiss',
      method: 'POST',
    } );
  }, [] );

  const rate = useCallback( ( rating ) => {
    if ( rating === 5 ) {
      window.open( REVIEW_URL, '_blank' );
      setState( 'hidden' );
    } else {
      setState( 'thankyou' );
    }

    apiFetch( {
      path: '/mission/v1/review-banner/rate',
      method: 'POST',
      data: { rating },
    } );
  }, [] );

  if ( state === 'hidden' ) {
    return null;
  }

  const formattedAmount = formatAmount( totalRaised );

  return (
    <div className="mission-review-banner">
      <button
        type="button"
        className="mission-review-banner__close"
        onClick={ dismiss }
        title={ __( 'Dismiss', 'mission' ) }
      >
        <svg
          width="14"
          height="14"
          viewBox="0 0 16 16"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
        >
          <path d="M4 4l8 8M12 4l-8 8" />
        </svg>
      </button>

      <div className="mission-review-banner__top">
        <h2 className="mission-review-banner__title">
          { __( 'Enjoying Mission?', 'mission' ) }
        </h2>
        { state === 'default' && (
          <p className="mission-review-banner__desc">
            { __( 'Congratulations on raising over', 'mission' ) }{ ' ' }
            <strong>{ formattedAmount }</strong>{ ' ' }
            { __(
              'with Mission! How would you rate your experience?',
              'mission'
            ) }
          </p>
        ) }
        { state === 'thankyou' && (
          <p className="mission-review-banner__thankyou">
            { __(
              "Thank you for your feedback! We'll use it to make Mission better.",
              'mission'
            ) }
          </p>
        ) }
      </div>

      { state === 'default' && (
        <div className="mission-review-banner__actions">
          <div
            className="mission-review-banner__stars"
            onMouseLeave={ () => {
              setIsHovering( false );
              setHoveredStar( 0 );
            } }
          >
            { [ 1, 2, 3, 4, 5 ].map( ( n ) => (
              <Star
                key={ n }
                filled={ isHovering ? n <= hoveredStar : true }
                onMouseEnter={ () => {
                  setIsHovering( true );
                  setHoveredStar( n );
                } }
                onClick={ () => rate( n ) }
              />
            ) ) }
          </div>
          <button
            type="button"
            className="mission-review-banner__dismiss-text"
            onClick={ dismiss }
          >
            { __( 'Dismiss', 'mission' ) }
          </button>
        </div>
      ) }
    </div>
  );
}
