/**
 * Team Title block — editor registration.
 *
 * The block resolves its team from the page it sits on (the team template), so
 * the editor shows a self-describing placeholder rather than real data.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { computePrimaryColorVars } from '@shared/color';
import metadata from './block.json';
import './style.scss';

function Edit() {
  const primaryColorVars = useMemo( () => {
    const color = window.missiondpBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

  return (
    <div
      { ...useBlockProps( { className: 'mission-tt-title' } ) }
      style={ primaryColorVars }
    >
      <h1 className="mission-tt-title__heading">
        { __( 'Team name', 'mission-donation-platform' ) }
      </h1>
      <p className="mission-tt-title__subtitle">
        { __( 'Team fundraising for', 'mission-donation-platform' ) }{ ' ' }
        { /* eslint-disable-next-line jsx-a11y/anchor-is-valid -- placeholder link */ }
        <a>{ __( 'Your Campaign', 'mission-donation-platform' ) }</a>
      </p>
    </div>
  );
}

registerBlockType( metadata, { edit: Edit } );
