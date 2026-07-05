import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import SkeletonBar from '@shared/components/SkeletonBar';
import DetailCard from './DetailCard';

function TimelineItem( { title, subtitle, date, dotClass } ) {
  return (
    <div className="mission-timeline__item is-reached">
      <div className={ `mission-timeline__dot ${ dotClass }` } />
      <div className="mission-timeline__title">{ title }</div>
      <div className="mission-timeline__date">{ date }</div>
      { subtitle && (
        <div className="mission-timeline__subtitle">{ subtitle }</div>
      ) }
    </div>
  );
}

/**
 * Activity timeline card shared by detail screens.
 *
 * Fetches `path`, maps each raw entry through `mapEntry` into
 * `{ title, subtitle?, date, dotClass }`, and renders a timeline. When the
 * fetch fails or returns nothing, `fallbackEvents` (already mapped) render
 * instead so the card never sits empty.
 *
 * @param {Object}   props                Component props.
 * @param {string}   props.title          Card title (defaults to "Activity").
 * @param {string}   props.path           REST path returning activity entries.
 * @param {Function} props.mapEntry       Maps a raw entry to a timeline event.
 * @param {Array}    props.fallbackEvents Events used when the API has none.
 * @return {JSX.Element} The card.
 */
export default function ActivityTimelineCard( {
  title,
  path,
  mapEntry,
  fallbackEvents = [],
} ) {
  const [ entries, setEntries ] = useState( null );
  const [ isLoading, setIsLoading ] = useState( true );

  useEffect( () => {
    if ( ! path ) {
      setIsLoading( false );
      return;
    }

    apiFetch( { path } )
      .then( ( data ) => setEntries( data ) )
      .catch( () => setEntries( null ) )
      .finally( () => setIsLoading( false ) );
  }, [ path ] );

  const useFallback = ! isLoading && ( ! entries || entries.length === 0 );
  const events = useFallback
    ? fallbackEvents
    : ( entries || [] ).map( mapEntry );

  return (
    <DetailCard
      title={ title || __( 'Activity', 'mission-donation-platform' ) }
    >
      { isLoading ? (
        <div className="mission-timeline">
          { [ 0, 1, 2 ].map( ( i ) => (
            <div key={ i } className="mission-timeline__item is-reached">
              <div className="mission-timeline__dot" />
              <SkeletonBar width="60%" height="14px" />
              <div style={ { marginTop: '6px' } }>
                <SkeletonBar width="40%" height="12px" />
              </div>
            </div>
          ) ) }
        </div>
      ) : (
        <div className="mission-timeline">
          { events.map( ( event, index ) => (
            <TimelineItem
              key={ index }
              title={ event.title }
              subtitle={ event.subtitle }
              date={ event.date }
              dotClass={ event.dotClass }
            />
          ) ) }
        </div>
      ) }
    </DetailCard>
  );
}
