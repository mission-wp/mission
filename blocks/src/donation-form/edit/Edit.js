import {
  useBlockProps,
  InspectorControls,
  useInnerBlocksProps,
  InnerBlocks,
} from '@wordpress/block-editor';
import { ComboboxControl, PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import FormPreview from './FormPreview';
import FormSettingsModal from './FormSettingsModal';

const CONFIRMATION_TEMPLATE = [
  [
    'core/heading',
    {
      content: __( 'Thank you!', 'mission' ),
      level: 2,
    },
  ],
  [
    'core/paragraph',
    {
      content: __(
        'Your donation has been processed successfully. You will receive a confirmation email shortly.',
        'mission'
      ),
    },
  ],
];

export default function Edit( {
  attributes,
  setAttributes,
  isSelected,
  clientId,
} ) {
  const { campaignId, confirmationType } = attributes;

  const [ campaigns, setCampaigns ] = useState( [] );
  const [ filterValue, setFilterValue ] = useState( '' );
  const [ isModalOpen, setIsModalOpen ] = useState( false );
  const [ pluginDefaults, setPluginDefaults ] = useState( null );
  const [ editorView, setEditorView ] = useState( 'form' );

  // Get the current post context for auto-detect.
  const {
    currentPostType,
    currentPostId,
    hasSelectedInnerBlock,
    allowedBlocks,
  } = useSelect(
    ( select ) => {
      const editor = select( 'core/editor' );
      const blockEditor = select( 'core/block-editor' );
      const allBlocks = select( 'core/blocks' )
        .getBlockTypes()
        .map( ( block ) => block.name )
        .filter( ( name ) => name !== 'mission/donation-form' );
      return {
        currentPostType: editor?.getCurrentPostType?.() || '',
        currentPostId: editor?.getCurrentPostId?.() || 0,
        hasSelectedInnerBlock: blockEditor.hasSelectedInnerBlock(
          clientId,
          true
        ),
        allowedBlocks: allBlocks,
      };
    },
    [ clientId ]
  );

  const isActive = isSelected || hasSelectedInnerBlock;

  // Fetch campaigns for the combobox.
  useEffect( () => {
    apiFetch( { path: '/mission/v1/campaigns?per_page=100' } )
      .then( ( response ) => {
        setCampaigns( Array.isArray( response ) ? response : [] );
      } )
      .catch( () => setCampaigns( [] ) );
  }, [] );

  // Prefetch plugin defaults so the settings modal opens instantly.
  useEffect( () => {
    apiFetch( { path: '/mission/v1/donation-form-settings' } )
      .then( setPluginDefaults )
      .catch( () => {} );
  }, [] );

  // Auto-generate a persistent form ID on first insert.
  useEffect( () => {
    if ( ! attributes.formId ) {
      const bytes = new Uint8Array( 4 );
      crypto.getRandomValues( bytes );
      const hex = Array.from( bytes, ( b ) =>
        b.toString( 16 ).padStart( 2, '0' )
      ).join( '' );
      setAttributes( { formId: `f_${ hex }` } );
    }
  }, [] ); // eslint-disable-line react-hooks/exhaustive-deps

  // Reset to form view when block is fully deselected.
  useEffect( () => {
    if ( ! isActive ) {
      setEditorView( 'form' );
    }
  }, [ isActive ] );

  // Auto-detect campaign when on a campaign post and no explicit ID set.
  let effectiveId = campaignId;
  if (
    ! effectiveId &&
    currentPostType === 'mission_campaign' &&
    currentPostId &&
    campaigns.length
  ) {
    const match = campaigns.find( ( c ) => c.post_id === currentPostId );
    effectiveId = match?.id || 0;
  }

  // Campaign options filtered by the combobox input.
  const campaignOptions = campaigns
    .filter( ( c ) => {
      if ( ! filterValue ) {
        return true;
      }
      return c.title.toLowerCase().includes( filterValue.toLowerCase() );
    } )
    .map( ( c ) => ( {
      value: c.id,
      label: c.title,
    } ) );

  const onCampaignChange = useCallback(
    ( val ) => {
      setAttributes( { campaignId: val ? Number( val ) : 0 } );
    },
    [ setAttributes ]
  );

  const showConfirmation = isActive && editorView === 'confirmation';

  const innerBlocksProps = useInnerBlocksProps(
    { className: 'mission-df-editor-confirmation__blocks' },
    {
      allowedBlocks,
      template: CONFIRMATION_TEMPLATE,
      renderAppender: showConfirmation
        ? InnerBlocks.DefaultBlockAppender
        : false,
    }
  );

  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Settings', 'mission' ) }>
          <ComboboxControl
            label={ __( 'Campaign', 'mission' ) }
            value={ campaignId || null }
            options={ campaignOptions }
            onChange={ onCampaignChange }
            onFilterValueChange={ setFilterValue }
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>
        { isActive && editorView === 'form' && (
          <div className="mission-df-editor-controls">
            <div className="mission-df-editor-controls__icon">
              <svg
                width="32"
                height="32"
                viewBox="0 0 24 24"
                fill="none"
                stroke="#2fa36b"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z" />
              </svg>
            </div>
            <h3 className="mission-df-editor-controls__title">
              { __( 'Donation Form', 'mission' ) }
            </h3>
            <div className="mission-df-editor-controls__campaign">
              <ComboboxControl
                label={ __( 'Select a Campaign', 'mission' ) }
                value={ campaignId || null }
                options={ campaignOptions }
                onChange={ onCampaignChange }
                onFilterValueChange={ setFilterValue }
              />
            </div>
            <div className="mission-df-editor-controls__actions">
              <button
                type="button"
                className="mission-df-editor-controls__settings-btn"
                onClick={ () => setIsModalOpen( true ) }
              >
                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <circle cx="12" cy="12" r="3" />
                  <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                </svg>
                { __( 'Form Settings', 'mission' ) }
              </button>
              { confirmationType !== 'redirect' && (
                <button
                  type="button"
                  className="mission-df-editor-controls__settings-btn"
                  onClick={ () => setEditorView( 'confirmation' ) }
                >
                  <svg
                    width="18"
                    height="18"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                  >
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                    <polyline points="22 4 12 14.01 9 11.01" />
                  </svg>
                  { __( 'Edit Confirmation', 'mission' ) }
                </button>
              ) }
            </div>
          </div>
        ) }

        { showConfirmation && (
          <div className="mission-df-editor-confirmation">
            <button
              type="button"
              className="mission-df-editor-confirmation__back"
              onClick={ () => setEditorView( 'form' ) }
            >
              &larr; { __( 'Back to form', 'mission' ) }
            </button>
            <h3 className="mission-df-editor-confirmation__title">
              { __( 'Confirmation Message', 'mission' ) }
            </h3>
            <p className="mission-df-editor-confirmation__hint">
              { __(
                'This is displayed after a successful donation. Add any blocks you like.',
                'mission'
              ) }
            </p>
          </div>
        ) }

        <div
          className="mission-df-editor-confirmation__editor"
          style={ {
            display: showConfirmation ? undefined : 'none',
          } }
        >
          <div { ...innerBlocksProps } />
        </div>

        { ! isActive && <FormPreview attributes={ attributes } /> }
      </div>
      { isModalOpen && (
        <FormSettingsModal
          attributes={ attributes }
          setAttributes={ setAttributes }
          pluginDefaults={ pluginDefaults }
          onClose={ () => setIsModalOpen( false ) }
        />
      ) }
    </>
  );
}
