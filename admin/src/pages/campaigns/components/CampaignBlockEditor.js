import { useState, useEffect, useMemo } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { useEntityBlockEditor, store as coreStore } from '@wordpress/core-data';
import {
  BlockEditorProvider,
  BlockList,
  BlockToolbar,
  BlockTools,
  Inserter,
  WritingFlow,
  ObserveTyping,
  __unstableEditorStyles as EditorStyles,
} from '@wordpress/block-editor';
import { Button, SlotFillProvider, Popover } from '@wordpress/components';
import { registerCoreBlocks } from '@wordpress/block-library';
import { __ } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

let blocksRegistered = false;

/**
 * Embedded block editor for campaign page content.
 *
 * Uses useEntityBlockEditor to read/write blocks from the campaign's
 * backing WP_Post via the WP REST API. Renders blocks directly in the
 * DOM (no iframe) for reliable styling in our custom admin page.
 *
 * @param {Object} props         Component props.
 * @param {number} props.postId  The WP_Post ID for the campaign.
 * @param {string} props.editUrl URL to the full post editor.
 */
export default function CampaignBlockEditor( { postId, editUrl } ) {
  const [ isReady, setIsReady ] = useState( false );

  // Register core blocks once.
  useEffect( () => {
    if ( ! blocksRegistered ) {
      registerCoreBlocks();
      blocksRegistered = true;
    }
    setIsReady( true );
  }, [] );

  // Load the post entity so useEntityBlockEditor has data to work with.
  const hasResolved = useSelect(
    ( select ) => {
      select( coreStore ).getEntityRecord(
        'postType',
        'mission_campaign',
        postId
      );
      return select( coreStore ).hasFinishedResolution( 'getEntityRecord', [
        'postType',
        'mission_campaign',
        postId,
      ] );
    },
    [ postId ]
  );

  if ( ! isReady || ! hasResolved ) {
    return (
      <div className="mission-block-editor">
        <div
          className="mission-block-editor__content"
          style={ {
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          } }
        >
          <span style={ { color: '#9b9ba8' } }>
            { __( 'Loading editor…', 'missionwp-donation-platform' ) }
          </span>
        </div>
      </div>
    );
  }

  return <CampaignEditor postId={ postId } editUrl={ editUrl } />;
}

function EditorToolbar( { editUrl } ) {
  return (
    <div className="mission-block-editor__toolbar">
      <div className="mission-block-editor__toolbar-left">
        <Inserter
          renderToggle={ ( { onToggle, disabled } ) => (
            <Button
              icon={ plus }
              label={ __(
                'Toggle block inserter',
                'missionwp-donation-platform'
              ) }
              onClick={ onToggle }
              disabled={ disabled }
              size="compact"
            />
          ) }
          position="bottom right"
        />
        <div className="mission-block-editor__toolbar-divider" />
        <BlockToolbar hideDragHandle />
      </div>
      { editUrl && (
        <a href={ editUrl } className="mission-block-editor__fullscreen-link">
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
            <path d="M11 7.5v4a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h4" />
            <path d="M7 7L12.5 1.5M10 1h3v3" />
          </svg>
          { __( 'Open full editor', 'missionwp-donation-platform' ) }
        </a>
      ) }
    </div>
  );
}

function CampaignEditor( { postId, editUrl } ) {
  const [ blocks, onInput, onChange ] = useEntityBlockEditor(
    'postType',
    'mission_campaign',
    { id: postId }
  );

  const settings = useMemo(
    () => ( {
      ...( window.missionEditorSettings || {} ),
      hasFixedToolbar: true,
    } ),
    []
  );

  return (
    <SlotFillProvider>
      <div className="mission-block-editor">
        <BlockEditorProvider
          value={ blocks }
          onInput={ onInput }
          onChange={ onChange }
          settings={ settings }
        >
          <EditorToolbar editUrl={ editUrl } />
          <div className="mission-block-editor__canvas">
            <EditorStyles
              styles={ settings.styles }
              scope=":where(.editor-styles-wrapper)"
            />
            <BlockTools>
              <WritingFlow className="editor-styles-wrapper" tabIndex={ -1 }>
                <ObserveTyping>
                  <BlockList />
                </ObserveTyping>
              </WritingFlow>
            </BlockTools>
          </div>
        </BlockEditorProvider>
      </div>
      <Popover.Slot />
    </SlotFillProvider>
  );
}
