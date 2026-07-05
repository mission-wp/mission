import { useState, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import DetailCard from '../../components/DetailCard';

/**
 * Editable fundraiser story card.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.fundraiser Fundraiser detail object.
 * @param {Function} props.onSaved    Called with the updated fundraiser.
 * @param {Function} props.onError    Called with an error message.
 * @return {JSX.Element} The card.
 */
export default function StoryCard( { fundraiser, onSaved, onError } ) {
  const [ story, setStory ] = useState( fundraiser.story || '' );
  const [ isSaving, setIsSaving ] = useState( false );

  useEffect( () => {
    setStory( fundraiser.story || '' );
  }, [ fundraiser.story ] );

  const isDirty = story !== ( fundraiser.story || '' );

  const save = async () => {
    setIsSaving( true );
    try {
      const updated = await apiFetch( {
        path: `/mission-donation-platform/v1/fundraisers/${ fundraiser.id }`,
        method: 'PUT',
        data: { story },
      } );
      onSaved( updated );
    } catch ( err ) {
      onError(
        err.message ||
          __( 'Failed to save the story.', 'mission-donation-platform' )
      );
    } finally {
      setIsSaving( false );
    }
  };

  return (
    <DetailCard title={ __( 'Story', 'mission-donation-platform' ) }>
      <div style={ { padding: '16px' } }>
        <textarea
          className="mission-field-textarea"
          rows={ 8 }
          style={ { width: '100%' } }
          value={ story }
          onChange={ ( e ) => setStory( e.target.value ) }
        />
        <div
          style={ {
            display: 'flex',
            justifyContent: 'flex-end',
            marginTop: '12px',
          } }
        >
          <Button
            variant="primary"
            isBusy={ isSaving }
            disabled={ isSaving || ! isDirty }
            onClick={ save }
            __next40pxDefaultSize
          >
            { __( 'Save Story', 'mission-donation-platform' ) }
          </Button>
        </div>
      </div>
    </DetailCard>
  );
}
