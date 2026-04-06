/**
 * Edit component for the Donor Wall block.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
  ComboboxControl,
  PanelBody,
  RangeControl,
  SelectControl,
  TextControl,
  ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { computePrimaryColorVars } from '@shared/color';
import apiFetch from '@wordpress/api-fetch';
import { formatAmount } from '@shared/currency';

const SORT_OPTIONS = [
  { label: __( 'Most Recent', 'mission' ), value: 'recent' },
  { label: __( 'Highest Amount', 'mission' ), value: 'highest' },
  { label: __( 'Earliest', 'mission' ), value: 'earliest' },
];

const SAMPLE_DONORS = [
  {
    initials: 'KW',
    name: 'Karen W.',
    amount: 250000,
    hoursAgo: 2,
    type: 'one_time',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'RH',
    name: 'Robert H.',
    amount: 10000,
    hoursAgo: 5,
    type: 'monthly',
    comment:
      'Keep up the great work! This organization has made such a difference in our community.',
    is_anonymous: false,
  },
  {
    initials: '?',
    name: 'Anonymous',
    amount: 5000,
    hoursAgo: 18,
    type: 'one_time',
    comment: null,
    is_anonymous: true,
  },
  {
    initials: 'LM',
    name: 'Lisa M.',
    amount: 7500,
    hoursAgo: 72,
    type: 'one_time',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'JP',
    name: 'James P.',
    amount: 15000,
    hoursAgo: 96,
    type: 'quarterly',
    comment: 'In memory of my father.',
    is_anonymous: false,
  },
  {
    initials: 'SM',
    name: 'Sarah M.',
    amount: 2500,
    hoursAgo: 168,
    type: 'one_time',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'TC',
    name: 'Thomas C.',
    amount: 50000,
    hoursAgo: 192,
    type: 'one_time',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'AG',
    name: 'Amanda G.',
    amount: 3500,
    hoursAgo: 240,
    type: 'monthly',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: '?',
    name: 'Anonymous',
    amount: 100000,
    hoursAgo: 288,
    type: 'one_time',
    comment: 'Wishing you all the best!',
    is_anonymous: true,
  },
  {
    initials: 'DR',
    name: 'David R.',
    amount: 20000,
    hoursAgo: 336,
    type: 'one_time',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'EW',
    name: 'Emily W.',
    amount: 8000,
    hoursAgo: 360,
    type: 'annually',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'MJ',
    name: 'Michael J.',
    amount: 45000,
    hoursAgo: 480,
    type: 'one_time',
    comment: 'Happy to support this cause.',
    is_anonymous: false,
  },
  {
    initials: 'NP',
    name: 'Nancy P.',
    amount: 1500,
    hoursAgo: 504,
    type: 'one_time',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'BT',
    name: 'Brian T.',
    amount: 30000,
    hoursAgo: 600,
    type: 'monthly',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'CL',
    name: 'Catherine L.',
    amount: 12000,
    hoursAgo: 720,
    type: 'one_time',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: '?',
    name: 'Anonymous',
    amount: 6000,
    hoursAgo: 840,
    type: 'one_time',
    comment: null,
    is_anonymous: true,
  },
  {
    initials: 'RK',
    name: 'Rachel K.',
    amount: 75000,
    hoursAgo: 960,
    type: 'one_time',
    comment: 'This means so much to me.',
    is_anonymous: false,
  },
  {
    initials: 'WH',
    name: 'William H.',
    amount: 4000,
    hoursAgo: 1080,
    type: 'quarterly',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'JD',
    name: 'Jennifer D.',
    amount: 18000,
    hoursAgo: 1200,
    type: 'one_time',
    comment: null,
    is_anonymous: false,
  },
  {
    initials: 'PG',
    name: 'Patrick G.',
    amount: 9500,
    hoursAgo: 1320,
    type: 'one_time',
    comment: null,
    is_anonymous: false,
  },
];

/**
 * Format hoursAgo into a human-readable string.
 *
 * @param {number} hoursAgo Hours since donation.
 * @return {string} Formatted string.
 */
function formatHoursAgo( hoursAgo ) {
  if ( hoursAgo < 24 ) {
    return `${ hoursAgo } hour${ hoursAgo !== 1 ? 's' : '' } ago`;
  }
  const days = Math.floor( hoursAgo / 24 );
  if ( days < 7 ) {
    return `${ days } day${ days !== 1 ? 's' : '' } ago`;
  }
  const weeks = Math.floor( days / 7 );
  return `${ weeks } week${ weeks !== 1 ? 's' : '' } ago`;
}

const FREQUENCY_LABELS = {
  monthly: __( 'Monthly', 'mission' ),
  quarterly: __( 'Quarterly', 'mission' ),
  annually: __( 'Annually', 'mission' ),
};

const FREQUENCY_SUFFIX = {
  monthly: '/mo',
  quarterly: '/qtr',
  annually: '/yr',
};

const ANONYMOUS_ICON = (
  <svg
    width="20"
    height="20"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
    <circle cx="12" cy="7" r="4" />
  </svg>
);

export default function Edit( { attributes, setAttributes } ) {
  const {
    campaignId,
    columns,
    showName,
    showAnonymous,
    showAvatar,
    avatarWidth,
    donorsPerPage,
    showAmount,
    showDate,
    showComments,
    commentLength,
    readMoreText,
    showSortControls,
    defaultSort,
    loadMoreText,
  } = attributes;

  const [ campaigns, setCampaigns ] = useState( [] );
  const [ isLoadingList, setIsLoadingList ] = useState( true );
  const [ filterValue, setFilterValue ] = useState( '' );

  const primaryColorVars = useMemo( () => {
    const color = window.missionBlockEditor?.primaryColor || '#2fa36b';
    return computePrimaryColorVars( color );
  }, [] );

  const { currentPostType, currentPostId } = useSelect( ( select ) => {
    const editor = select( 'core/editor' );
    return {
      currentPostType: editor?.getCurrentPostType?.() || '',
      currentPostId: editor?.getCurrentPostId?.() || 0,
    };
  }, [] );

  useEffect( () => {
    apiFetch( { path: '/mission/v1/campaigns?per_page=100' } )
      .then( ( response ) => {
        setCampaigns( Array.isArray( response ) ? response : [] );
      } )
      .catch( () => setCampaigns( [] ) )
      .finally( () => setIsLoadingList( false ) );
  }, [] );

  // Resolve effective campaign ID.
  let effectiveId = campaignId;
  if (
    ! effectiveId &&
    currentPostType === 'mission_campaign' &&
    currentPostId &&
    ! isLoadingList
  ) {
    const match = campaigns.find( ( c ) => c.post_id === currentPostId );
    effectiveId = match?.id || 0;
  }

  const campaign = effectiveId
    ? campaigns.find( ( c ) => c.id === effectiveId )
    : null;

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

  // Filter, sort, and paginate sample data to match settings.
  const filteredDonors = SAMPLE_DONORS.filter(
    ( d ) => showAnonymous || ! d.is_anonymous
  );
  const sortedDonors = [ ...filteredDonors ].sort( ( a, b ) => {
    if ( defaultSort === 'highest' ) {
      return b.amount - a.amount;
    }
    if ( defaultSort === 'earliest' ) {
      return b.hoursAgo - a.hoursAgo;
    }
    // recent (default): smallest hoursAgo first.
    return a.hoursAgo - b.hoursAgo;
  } );
  const totalDonors = sortedDonors.length;
  const visibleDonors = sortedDonors.slice( 0, donorsPerPage );

  function renderCard( donor, index ) {
    return (
      <div
        key={ index }
        className={ `mission-dw-card${
          donor.is_anonymous ? ' is-anonymous' : ''
        }` }
      >
        { showAvatar && (
          <div
            className="mission-dw-avatar"
            style={ {
              width: avatarWidth,
              height: avatarWidth,
              fontSize: avatarWidth * 0.35,
            } }
          >
            { donor.is_anonymous ? ANONYMOUS_ICON : donor.initials }
          </div>
        ) }
        <div className="mission-dw-body">
          <div className="mission-dw-top">
            { showName && (
              <span className="mission-dw-name">{ donor.name }</span>
            ) }
            { showAmount && (
              <span className="mission-dw-amount">
                { formatAmount( donor.amount ) }
                { donor.type !== 'one_time' &&
                  FREQUENCY_SUFFIX[ donor.type ] && (
                    <span className="mission-dw-freq">
                      { FREQUENCY_SUFFIX[ donor.type ] }
                    </span>
                  ) }
              </span>
            ) }
          </div>
          <div className="mission-dw-meta">
            { showDate && (
              <span className="mission-dw-date">
                { formatHoursAgo( donor.hoursAgo ) }
              </span>
            ) }
            { donor.type !== 'one_time' && FREQUENCY_LABELS[ donor.type ] && (
              <span className="mission-dw-recurring">
                { FREQUENCY_LABELS[ donor.type ] }
              </span>
            ) }
          </div>
          { showComments && donor.comment && (
            <div className="mission-dw-comment">
              { donor.comment.length > commentLength
                ? donor.comment.substring( 0, commentLength ) + '…'
                : donor.comment }
              { donor.comment.length > commentLength && (
                <span className="mission-dw-read-more">{ readMoreText }</span>
              ) }
            </div>
          ) }
        </div>
      </div>
    );
  }

  function renderPreview() {
    if ( isLoadingList ) {
      return (
        <div
          className="mission-donor-wall mission-dw-skeleton"
          style={ primaryColorVars }
        >
          <div
            className="mission-dw-skeleton-bar"
            style={ { width: '30%', height: 14 } }
          />
          <div
            className="mission-dw-skeleton-grid"
            style={ { gridTemplateColumns: `repeat(${ columns }, 1fr)` } }
          >
            { [ 1, 2, 3, 4 ].map( ( i ) => (
              <div key={ i } className="mission-dw-skeleton-card">
                <div
                  className="mission-dw-skeleton-bar"
                  style={ { width: 40, height: 40, borderRadius: '50%' } }
                />
                <div style={ { flex: 1 } }>
                  <div
                    className="mission-dw-skeleton-bar"
                    style={ { width: '70%', height: 14 } }
                  />
                  <div
                    className="mission-dw-skeleton-bar"
                    style={ { width: '40%', height: 12, marginTop: 6 } }
                  />
                </div>
              </div>
            ) ) }
          </div>
        </div>
      );
    }

    if ( ! campaign ) {
      return (
        <div
          className="mission-donor-wall mission-dw-empty-editor"
          style={ primaryColorVars }
        >
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
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
          </svg>
          <p>
            { __(
              'Select a campaign or place this block on a campaign page.',
              'mission'
            ) }
          </p>
        </div>
      );
    }

    return (
      <div
        className={ `mission-donor-wall${ showAvatar ? ' has-avatars' : '' }` }
        style={ primaryColorVars }
      >
        <div className="mission-dw-toolbar">
          { showSortControls && (
            <select
              className="mission-dw-sort"
              disabled
              value={ defaultSort }
              readOnly
            >
              { SORT_OPTIONS.map( ( opt ) => (
                <option key={ opt.value } value={ opt.value }>
                  { opt.label }
                </option>
              ) ) }
            </select>
          ) }
          <span className="mission-dw-sample-notice">
            { __( 'Preview uses sample data.', 'mission' ) }
          </span>
        </div>
        <div
          className="mission-dw-grid"
          style={ { gridTemplateColumns: `repeat(${ columns }, 1fr)` } }
        >
          { visibleDonors.map( renderCard ) }
        </div>
        { visibleDonors.length < totalDonors && (
          <div className="mission-dw-footer">
            <button type="button" className="mission-dw-load-more" disabled>
              { loadMoreText }
            </button>
            <span className="mission-dw-count">
              { `${ __( 'Showing', 'mission' ) } ${
                visibleDonors.length
              } ${ __( 'of', 'mission' ) } ${ totalDonors }` }
            </span>
          </div>
        ) }
      </div>
    );
  }

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
            help={
              ! campaignId
                ? __(
                    'If empty, the campaign will be detected from the current post.',
                    'mission'
                  )
                : undefined
            }
          />
          <RangeControl
            label={ __( 'Columns', 'mission' ) }
            value={ columns }
            onChange={ ( val ) => setAttributes( { columns: val } ) }
            min={ 1 }
            max={ 4 }
            step={ 1 }
          />
          <RangeControl
            label={ __( 'Donors per page', 'mission' ) }
            value={ donorsPerPage }
            onChange={ ( val ) => setAttributes( { donorsPerPage: val } ) }
            min={ 4 }
            max={ 50 }
            step={ 1 }
          />
          <SelectControl
            label={ __( 'Default sort', 'mission' ) }
            value={ defaultSort }
            options={ SORT_OPTIONS }
            onChange={ ( val ) => setAttributes( { defaultSort: val } ) }
          />
          <TextControl
            label={ __( 'Load more text', 'mission' ) }
            value={ loadMoreText }
            onChange={ ( val ) => setAttributes( { loadMoreText: val } ) }
          />
        </PanelBody>
        <PanelBody title={ __( 'Display', 'mission' ) } initialOpen={ false }>
          <ToggleControl
            label={ __( 'Show name', 'mission' ) }
            checked={ showName }
            onChange={ ( val ) => setAttributes( { showName: val } ) }
          />
          <ToggleControl
            label={ __( 'Show anonymous donations', 'mission' ) }
            checked={ showAnonymous }
            onChange={ ( val ) => setAttributes( { showAnonymous: val } ) }
          />
          <ToggleControl
            label={ __( 'Show avatar', 'mission' ) }
            checked={ showAvatar }
            onChange={ ( val ) => setAttributes( { showAvatar: val } ) }
          />
          { showAvatar && (
            <RangeControl
              label={ __( 'Avatar size (px)', 'mission' ) }
              value={ avatarWidth }
              onChange={ ( val ) => setAttributes( { avatarWidth: val } ) }
              min={ 24 }
              max={ 96 }
              step={ 2 }
            />
          ) }
          <ToggleControl
            label={ __( 'Show donation amount', 'mission' ) }
            checked={ showAmount }
            onChange={ ( val ) => setAttributes( { showAmount: val } ) }
          />
          <ToggleControl
            label={ __( 'Show date', 'mission' ) }
            checked={ showDate }
            onChange={ ( val ) => setAttributes( { showDate: val } ) }
          />
          <ToggleControl
            label={ __( 'Show sort controls', 'mission' ) }
            checked={ showSortControls }
            onChange={ ( val ) => setAttributes( { showSortControls: val } ) }
          />
          <ToggleControl
            label={ __( 'Show comments', 'mission' ) }
            checked={ showComments }
            onChange={ ( val ) => setAttributes( { showComments: val } ) }
          />
          { showComments && (
            <>
              <TextControl
                label={ __( 'Comment length', 'mission' ) }
                value={ String( commentLength ) }
                onChange={ ( val ) =>
                  setAttributes( { commentLength: parseInt( val, 10 ) || 0 } )
                }
                type="number"
                min={ 0 }
              />
              <TextControl
                label={ __( 'Read more text', 'mission' ) }
                value={ readMoreText }
                onChange={ ( val ) => setAttributes( { readMoreText: val } ) }
              />
            </>
          ) }
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>{ renderPreview() }</div>
    </>
  );
}
