import { useState } from '@wordpress/element';
import {
  Modal,
  Button,
  TextControl,
  __experimentalHStack as HStack,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function ConfirmationModal( {
  title,
  message,
  confirmLabel,
  typedConfirm,
  isDanger,
  isRunning,
  onConfirm,
  onCancel,
} ) {
  const [ typedValue, setTypedValue ] = useState( '' );

  const needsTyped = !! typedConfirm;
  const isDisabled = isRunning || ( needsTyped && typedValue !== typedConfirm );

  return (
    <Modal
      title={ title || __( 'Are you sure?', 'missionwp-donation-platform' ) }
      onRequestClose={ onCancel }
      className={ `mission-cleanup-modal${
        isDanger ? ' mission-cleanup-modal--danger' : ''
      }` }
      size="small"
    >
      <VStack spacing={ 4 }>
        <Text className="mission-cleanup-modal__message">{ message }</Text>

        { needsTyped && (
          <TextControl
            label={
              <>
                { __( 'Type', 'missionwp-donation-platform' ) }{ ' ' }
                <strong>{ typedConfirm }</strong>{ ' ' }
                { __( 'to confirm', 'missionwp-donation-platform' ) }
              </>
            }
            value={ typedValue }
            onChange={ setTypedValue }
            autoComplete="off"
          />
        ) }

        <HStack justify="flex-end" spacing={ 2 }>
          <Button
            variant="secondary"
            onClick={ onCancel }
            disabled={ isRunning }
          >
            { __( 'Cancel', 'missionwp-donation-platform' ) }
          </Button>
          <Button
            variant="primary"
            onClick={ () => onConfirm( needsTyped ? typedValue : undefined ) }
            disabled={ isDisabled }
            isBusy={ isRunning }
            className={
              isDanger ? 'mission-cleanup-modal__confirm--danger' : ''
            }
          >
            { confirmLabel || __( 'Confirm', 'missionwp-donation-platform' ) }
          </Button>
        </HStack>
      </VStack>
    </Modal>
  );
}
