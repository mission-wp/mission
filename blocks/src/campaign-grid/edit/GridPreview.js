/**
 * Grid preview container for the Campaign Grid editor.
 */
import { __ } from '@wordpress/i18n';
import CardPreview from './CardPreview';

function SkeletonCard() {
  return (
    <div className="mission-cg-skeleton-card">
      <div
        className="mission-cg-skeleton-bar"
        style={ { width: '100%', height: 220 } }
      />
      <div style={ { padding: '20px 20px 24px' } }>
        <div
          className="mission-cg-skeleton-bar"
          style={ { width: '70%', height: 20, marginBottom: 8 } }
        />
        <div
          className="mission-cg-skeleton-bar"
          style={ { width: '100%', height: 14, marginBottom: 4 } }
        />
        <div
          className="mission-cg-skeleton-bar"
          style={ { width: '80%', height: 14, marginBottom: 20 } }
        />
        <div
          className="mission-cg-skeleton-bar"
          style={ {
            width: '100%',
            height: 8,
            borderRadius: 4,
            marginBottom: 16,
          } }
        />
        <div
          className="mission-cg-skeleton-bar"
          style={ { width: '100%', height: 42, borderRadius: 4 } }
        />
      </div>
    </div>
  );
}

export default function GridPreview( {
  campaigns,
  isLoading,
  columns,
  attributes,
} ) {
  if ( isLoading ) {
    return (
      <div
        className="mission-cg-grid"
        style={ { '--mission-cg-columns': columns } }
      >
        { Array.from( { length: columns }, ( _, i ) => (
          <SkeletonCard key={ i } />
        ) ) }
      </div>
    );
  }

  if ( ! campaigns.length ) {
    return (
      <div className="mission-cg-grid">
        <div className="mission-cg-empty">
          <svg
            width="28"
            height="28"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <rect x="3" y="3" width="7" height="7" />
            <rect x="14" y="3" width="7" height="7" />
            <rect x="3" y="14" width="7" height="7" />
            <rect x="14" y="14" width="7" height="7" />
          </svg>
          <p>{ __( 'No campaigns found.', 'mission-donation-platform' ) }</p>
        </div>
      </div>
    );
  }

  return (
    <div
      className="mission-cg-grid"
      style={ { '--mission-cg-columns': columns } }
    >
      { campaigns.map( ( campaign ) => (
        <CardPreview
          key={ campaign.id }
          campaign={ campaign }
          attributes={ attributes }
        />
      ) ) }
    </div>
  );
}
