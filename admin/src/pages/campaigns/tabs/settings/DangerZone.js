import { useState } from '@wordpress/element';
import {
  Button,
  Modal,
  __experimentalVStack as VStack,
  __experimentalHStack as HStack,
  __experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export default function DangerZone( { campaignId, campaignTitle } ) {
  const [ showConfirm, setShowConfirm ] = useState( false );
  const [ isDeleting, setIsDeleting ] = useState( false );

  const adminUrl = window.missiondpAdmin?.adminUrl || '';

  const handleDelete = async () => {
    setIsDeleting( true );
    try {
      await apiFetch( {
        path: `/mission-donation-platform/v1/campaigns/${ campaignId }`,
        method: 'DELETE',
      } );
      window.location.href = `${ adminUrl }admin.php?page=mission-donation-platform-campaigns`;
    } catch {
      setIsDeleting( false );
      setShowConfirm( false );
    }
  };

  return (
    <div className="mission-card mission-danger-zone">
      <h3 className="mission-danger-zone__title">
        { __( 'Danger Zone', 'mission-donation-platform' ) }
      </h3>
      <p className="mission-danger-zone__desc">
        { __(
          'Permanently delete this campaign. This action cannot be undone.',
          'mission-donation-platform'
        ) }
      </p>
      <button
        type="button"
        className="mission-danger-zone__btn"
        onClick={ () => setShowConfirm( true ) }
      >
        <svg
          width="14"
          height="14"
          viewBox="0 0 14 14"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M2 3.5h10M4.5 3.5V2.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v1M11 3.5l-.5 8.5a1 1 0 0 1-1 1H4.5a1 1 0 0 1-1-1L3 3.5" />
          <path d="M5.5 6v4M8.5 6v4" />
        </svg>
        { __( 'Delete Campaign', 'mission-donation-platform' ) }
      </button>

      { showConfirm && (
        <Modal
          title={ __( 'Delete Campaign', 'mission-donation-platform' ) }
          onRequestClose={ () => setShowConfirm( false ) }
          size="small"
        >
          <VStack spacing={ 4 }>
            <Text>
              { __(
                'Are you sure you want to delete',
                'mission-donation-platform'
              ) }{ ' ' }
              <strong>{ campaignTitle }</strong>?{ ' ' }
              { __(
                'This action cannot be undone.',
                'mission-donation-platform'
              ) }
            </Text>
            <HStack justify="flex-end">
              <Button
                variant="tertiary"
                onClick={ () => setShowConfirm( false ) }
                __next40pxDefaultSize
              >
                { __( 'Cancel', 'mission-donation-platform' ) }
              </Button>
              <Button
                variant="primary"
                isDestructive
                isBusy={ isDeleting }
                disabled={ isDeleting }
                onClick={ handleDelete }
                __next40pxDefaultSize
              >
                { __( 'Delete', 'mission-donation-platform' ) }
              </Button>
            </HStack>
          </VStack>
        </Modal>
      ) }
    </div>
  );
}
