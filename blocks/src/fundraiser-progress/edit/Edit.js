/**
 * Edit component for the Fundraiser Progress block.
 *
 * The block resolves its fundraiser from the page it sits on (the fundraiser
 * template), so the editor shows a representative sample preview rather than a
 * picker. Options mirror the Campaign Progress block.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
  PanelBody,
  SelectControl,
  TextControl,
  ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { computePrimaryColorVars } from '@shared/color';
import { formatAmount } from '@shared/currency';

const DONATE_BUTTON_OPTIONS = [
  {
    label: __( 'Scroll to donation form', 'mission-donation-platform' ),
    value: 'scroll',
  },
  { label: __( 'Custom URL', 'mission-donation-platform' ), value: 'url' },
  { label: __( 'Hide', 'mission-donation-platform' ), value: 'hide' },
];

// Representative figures for the editor preview only.
const SAMPLE = {
  raised: 125000,
  goal: 200000,
  donations: 18,
  donors: 15,
};

export default function Edit( { attributes, setAttributes } ) {
  const {
    donateButtonAction,
    donateButtonUrl,
    showDonations,
    showDonors,
    showShare,
  } = attributes;

  const primaryColorVars = useMemo( () => {
    const color = window.missiondpBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

  const percentage = Math.round( ( SAMPLE.raised / SAMPLE.goal ) * 100 );
  const showStats = showDonations || showDonors;
  const showDonate = donateButtonAction !== 'hide';

  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Settings', 'mission-donation-platform' ) }>
          <SelectControl
            label={ __( 'Donate button', 'mission-donation-platform' ) }
            value={ donateButtonAction }
            options={ DONATE_BUTTON_OPTIONS }
            onChange={ ( val ) => setAttributes( { donateButtonAction: val } ) }
          />
          { donateButtonAction === 'url' && (
            <TextControl
              label={ __( 'Donate URL', 'mission-donation-platform' ) }
              value={ donateButtonUrl }
              onChange={ ( val ) => setAttributes( { donateButtonUrl: val } ) }
              type="url"
              placeholder="https://..."
            />
          ) }
          <ToggleControl
            label={ __( 'Show donations', 'mission-donation-platform' ) }
            checked={ showDonations }
            onChange={ ( val ) => setAttributes( { showDonations: val } ) }
          />
          <ToggleControl
            label={ __( 'Show donors', 'mission-donation-platform' ) }
            checked={ showDonors }
            onChange={ ( val ) => setAttributes( { showDonors: val } ) }
          />
          <ToggleControl
            label={ __( 'Show share button', 'mission-donation-platform' ) }
            checked={ showShare }
            onChange={ ( val ) => setAttributes( { showShare: val } ) }
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>
        <div className="mission-progress" style={ primaryColorVars }>
          <div className="mission-progress__header">
            <span className="mission-progress__raised">
              { formatAmount( SAMPLE.raised ) }
            </span>
            <span className="mission-progress__goal">
              { sprintf(
                /* translators: %s: formatted goal amount */
                __( 'raised of %s goal', 'mission-donation-platform' ),
                formatAmount( SAMPLE.goal )
              ) }
            </span>
            <span className="mission-progress__percentage">
              { percentage + '%' }
            </span>
          </div>
          <div className="mission-progress__bar">
            <div
              className="mission-progress__bar-fill"
              style={ { '--bar-width': percentage + '%' } }
            />
          </div>
          { showStats && (
            <div className="mission-progress__stats">
              { showDonations && (
                <div className="mission-progress__stat">
                  <span className="mission-progress__stat-value">
                    { SAMPLE.donations }
                  </span>
                  <span className="mission-progress__stat-label">
                    { __( 'donations', 'mission-donation-platform' ) }
                  </span>
                </div>
              ) }
              { showDonors && (
                <div className="mission-progress__stat">
                  <span className="mission-progress__stat-value">
                    { SAMPLE.donors }
                  </span>
                  <span className="mission-progress__stat-label">
                    { __( 'donors', 'mission-donation-platform' ) }
                  </span>
                </div>
              ) }
            </div>
          ) }
          { ( showDonate || showShare ) && (
            <div className="mission-progress__actions">
              { showDonate && (
                <span className="mission-progress__btn">
                  { __( 'Donate Now', 'mission-donation-platform' ) }
                </span>
              ) }
              { showShare && (
                <span
                  className={
                    showDonate
                      ? 'mission-progress__btn mission-progress__btn--secondary'
                      : 'mission-progress__btn'
                  }
                >
                  { __( 'Share', 'mission-donation-platform' ) }
                </span>
              ) }
            </div>
          ) }
        </div>
      </div>
    </>
  );
}
