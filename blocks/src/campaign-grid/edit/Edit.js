/**
 * Edit component for the Campaign Grid block.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
  PanelBody,
  RangeControl,
  SelectControl,
  TextControl,
  ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import GridPreview from './GridPreview';

const SORT_OPTIONS = [
  { label: __( 'Newest first', 'mission' ), value: 'date_created|DESC' },
  { label: __( 'Oldest first', 'mission' ), value: 'date_created|ASC' },
  { label: __( 'Most funded', 'mission' ), value: 'total_raised|DESC' },
  { label: __( 'Most donors', 'mission' ), value: 'donor_count|DESC' },
  { label: __( 'Ending soonest', 'mission' ), value: 'date_end|ASC' },
  { label: __( 'Alphabetical', 'mission' ), value: 'title|ASC' },
];

const STATUS_OPTIONS = [
  { label: __( 'Active campaigns', 'mission' ), value: 'active' },
  { label: __( 'Ended campaigns', 'mission' ), value: 'ended' },
  { label: __( 'All campaigns', 'mission' ), value: 'all' },
];

export default function Edit( { attributes, setAttributes } ) {
  const {
    statusFilter,
    columns,
    numberOfCampaigns,
    orderby,
    order,
    showImage,
    showTag,
    showDescription,
    showProgressBar,
    showDonorCount,
    buttonText,
  } = attributes;

  const [ campaigns, setCampaigns ] = useState( [] );
  const [ isLoading, setIsLoading ] = useState( true );

  const sortValue = `${ orderby }|${ order }`;

  useEffect( () => {
    setIsLoading( true );

    const params = new URLSearchParams( {
      per_page: String( numberOfCampaigns ),
      orderby: orderby === 'date_created' ? 'date' : orderby,
      order,
    } );

    if ( statusFilter !== 'all' ) {
      params.set( 'status', statusFilter );
    }

    apiFetch( { path: `/mission/v1/campaigns?${ params }` } )
      .then( ( response ) => {
        setCampaigns( Array.isArray( response ) ? response : [] );
      } )
      .catch( () => setCampaigns( [] ) )
      .finally( () => setIsLoading( false ) );
  }, [ statusFilter, numberOfCampaigns, orderby, order ] );

  function onSortChange( value ) {
    const [ newOrderby, newOrder ] = value.split( '|' );
    setAttributes( { orderby: newOrderby, order: newOrder } );
  }

  return (
    <>
      <InspectorControls>
        <PanelBody title={ __( 'Content', 'mission' ) }>
          <SelectControl
            label={ __( 'Show campaigns', 'mission' ) }
            value={ statusFilter }
            options={ STATUS_OPTIONS }
            onChange={ ( val ) => setAttributes( { statusFilter: val } ) }
          />
          <TextControl
            label={ __( 'Number of campaigns', 'mission' ) }
            type="number"
            min={ 1 }
            value={ numberOfCampaigns }
            onChange={ ( val ) =>
              setAttributes( { numberOfCampaigns: parseInt( val, 10 ) || 1 } )
            }
          />
          <SelectControl
            label={ __( 'Sort by', 'mission' ) }
            value={ sortValue }
            options={ SORT_OPTIONS }
            onChange={ onSortChange }
          />
        </PanelBody>
        <PanelBody title={ __( 'Display', 'mission' ) } initialOpen={ false }>
          <RangeControl
            label={ __( 'Columns', 'mission' ) }
            value={ columns }
            onChange={ ( val ) => setAttributes( { columns: val } ) }
            min={ 1 }
            max={ 3 }
          />
          <ToggleControl
            label={ __( 'Show image', 'mission' ) }
            checked={ showImage }
            onChange={ ( val ) => setAttributes( { showImage: val } ) }
          />
          { showImage && (
            <ToggleControl
              label={ __( 'Show status tag', 'mission' ) }
              checked={ showTag }
              onChange={ ( val ) => setAttributes( { showTag: val } ) }
            />
          ) }
          <ToggleControl
            label={ __( 'Show description', 'mission' ) }
            checked={ showDescription }
            onChange={ ( val ) => setAttributes( { showDescription: val } ) }
          />
          <ToggleControl
            label={ __( 'Show progress bar', 'mission' ) }
            checked={ showProgressBar }
            onChange={ ( val ) => setAttributes( { showProgressBar: val } ) }
          />
          { showProgressBar && (
            <ToggleControl
              label={ __( 'Show donor count', 'mission' ) }
              checked={ showDonorCount }
              onChange={ ( val ) => setAttributes( { showDonorCount: val } ) }
            />
          ) }
          <TextControl
            label={ __( 'Button text', 'mission' ) }
            value={ buttonText }
            onChange={ ( val ) => setAttributes( { buttonText: val } ) }
          />
        </PanelBody>
      </InspectorControls>
      <div { ...useBlockProps() }>
        <GridPreview
          campaigns={ campaigns }
          isLoading={ isLoading }
          columns={ columns }
          attributes={ attributes }
        />
      </div>
    </>
  );
}
