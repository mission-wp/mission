import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import {
  Spinner,
  __experimentalVStack as VStack,
  __experimentalText as Text,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { minorToMajor, majorToMinor } from '@shared/currencies';
import { getCurrencyCode } from '@shared/currency';
import Toast, { TOAST_DURATION } from '../../components/Toast';
import CampaignHero from './CampaignHero';
import CampaignTabNav from './CampaignTabNav';
import OverviewTab from './tabs/OverviewTab';
import EditPageTab from './tabs/EditPageTab';
import SettingsTab from './tabs/SettingsTab';

const BRAND_COLOR = '#2FA36B';

function buildFormState( campaign ) {
  const meta = campaign.meta || {};
  const code = getCurrencyCode();

  const goalType = campaign.goal_type || 'amount';

  // Convert goal from cents to display value for amount goals; use raw value otherwise.
  let goalDisplay = '';
  if ( campaign.goal_amount ) {
    goalDisplay =
      goalType === 'amount'
        ? String( minorToMajor( campaign.goal_amount, code ) )
        : String( campaign.goal_amount );
  }

  return {
    // Edit Page.
    has_campaign_page: campaign.has_campaign_page ?? true,
    show_in_listings: campaign.show_in_listings ?? true,
    slug: campaign.slug || '',
    excerpt: campaign.excerpt || '',
    image: campaign.image || null,
    image_url: campaign.image_url || '',

    // Settings — Campaign Details.
    goal_amount: goalDisplay,
    goal_type: goalType,
    close_on_goal: meta.close_on_goal || false,
    start_date: campaign.date_start
      ? campaign.date_start.split( /[T ]/ )[ 0 ]
      : '',
    end_date: campaign.date_end ? campaign.date_end.split( /[T ]/ )[ 0 ] : '',

    // Settings — When Campaign Ends.
    stop_donations_on_end: meta.stop_donations_on_end ?? true,
    show_ended_message: meta.show_ended_message || false,
    remove_from_listings_on_end: meta.remove_from_listings_on_end || false,
    recurring_end_behavior: meta.recurring_end_behavior || 'keep',
    recurring_redirect_campaign: meta.recurring_redirect_campaign || '',
  };
}

export default function CampaignDetail( { id } ) {
  const [ campaign, setCampaign ] = useState( null );
  const [ isLoading, setIsLoading ] = useState( true );
  const [ error, setError ] = useState( null );
  const [ activeTab, setActiveTab ] = useState( () => {
    try {
      return (
        window.localStorage.getItem( `mission_campaign_tab_${ id }` ) ||
        'overview'
      );
    } catch {
      return 'overview';
    }
  } );

  const handleTabChange = useCallback(
    ( tab ) => {
      setActiveTab( tab );
      try {
        window.localStorage.setItem( `mission_campaign_tab_${ id }`, tab );
      } catch {
        // Storage full — silently ignore.
      }
    },
    [ id ]
  );
  const [ formState, setFormState ] = useState( null );
  const savedFormState = useRef( null );
  const [ isSaving, setIsSaving ] = useState( false );
  const [ saveSuccess, setSaveSuccess ] = useState( false );
  const [ saveError, setSaveError ] = useState( false );
  const [ toast, setToast ] = useState( null );
  const [ toastKey, setToastKey ] = useState( 0 );
  const clearToast = useCallback( () => setToast( null ), [] );

  useEffect( () => {
    const fetchCampaign = async () => {
      try {
        const data = await apiFetch( {
          path: `/mission-donation-platform/v1/campaigns/${ id }`,
        } );
        setCampaign( data );
        const initial = buildFormState( data );
        setFormState( initial );
        savedFormState.current = initial;
      } catch ( err ) {
        setError(
          err.message ||
            __( 'Failed to load campaign.', 'mission-donation-platform' )
        );
      } finally {
        setIsLoading( false );
      }
    };
    fetchCampaign();
  }, [ id ] );

  const { saveEditedEntityRecord, invalidateResolution } =
    useDispatch( coreStore );

  // Detect unsaved block editor changes.
  const hasBlockEdits = useSelect(
    ( select ) => {
      if ( ! campaign?.post_id ) {
        return false;
      }
      return select( coreStore ).hasEditsForEntityRecord(
        'postType',
        'missiondp_campaign',
        campaign.post_id
      );
    },
    [ campaign?.post_id ]
  );

  // Detect unsaved form field changes.
  const isFormDirty =
    !! formState &&
    !! savedFormState.current &&
    JSON.stringify( formState ) !== JSON.stringify( savedFormState.current );

  const isDirty = isFormDirty || hasBlockEdits;

  useEffect( () => {
    if ( ! isDirty ) {
      return;
    }

    const handleBeforeUnload = ( event ) => {
      event.preventDefault();
    };

    window.addEventListener( 'beforeunload', handleBeforeUnload );
    return () => {
      window.removeEventListener( 'beforeunload', handleBeforeUnload );
    };
  }, [ isDirty ] );

  const adminUrl = window.missiondpAdmin?.adminUrl || '';
  const campaignsUrl = `${ adminUrl }admin.php?page=mission-donation-platform-campaigns`;

  const handleSave = async () => {
    setIsSaving( true );

    const goalNum = Number(
      String( formState.goal_amount ).replace( /,/g, '' )
    );

    if ( goalNum < 0 ) {
      setSaveError( true );
      setTimeout( () => setSaveError( false ), TOAST_DURATION );
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'error',
        message: __(
          'Goal amount cannot be negative.',
          'mission-donation-platform'
        ),
      } );
      setIsSaving( false );
      return;
    }

    const code = getCurrencyCode();

    const goalType = formState.goal_type || 'amount';

    let goalAmount = 0;
    if ( goalNum > 0 ) {
      goalAmount =
        goalType === 'amount' ? majorToMinor( goalNum, code ) : goalNum;
    }

    const body = {
      goal_type: goalType,
      goal_amount: goalAmount,
      date_start: formState.start_date || null,
      date_end: formState.end_date || null,
      excerpt: formState.excerpt,
      slug: formState.slug,
      image: formState.image || null,
      has_campaign_page: formState.has_campaign_page,
      show_in_listings: formState.show_in_listings,
      close_on_goal: formState.close_on_goal,
      stop_donations_on_end: formState.stop_donations_on_end,
      show_ended_message: formState.show_ended_message,
      remove_from_listings_on_end: formState.remove_from_listings_on_end,
      recurring_end_behavior: formState.recurring_end_behavior,
      recurring_redirect_campaign: formState.recurring_redirect_campaign,
    };

    try {
      // Save block editor content via the WP entity system.
      if ( campaign?.post_id ) {
        await saveEditedEntityRecord(
          'postType',
          'missiondp_campaign',
          campaign.post_id
        );
      }

      const updated = await apiFetch( {
        path: `/mission-donation-platform/v1/campaigns/${ id }`,
        method: 'PUT',
        data: body,
      } );
      setCampaign( updated );
      const saved = buildFormState( updated );
      setFormState( saved );
      savedFormState.current = saved;

      // Update preloaded campaign image data so the block editor
      // reflects the current image (or its removal).
      window.missiondpCampaignImage = updated.image
        ? { campaignId: updated.id, imageUrls: updated.image_urls || {} }
        : null;

      // Refetch the post entity so the block editor re-renders.
      if ( campaign?.post_id ) {
        invalidateResolution( 'getEntityRecord', [
          'postType',
          'missiondp_campaign',
          campaign.post_id,
        ] );
      }
      setSaveSuccess( true );
      setTimeout( () => setSaveSuccess( false ), TOAST_DURATION );
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'success',
        message: __( 'Campaign saved.', 'mission-donation-platform' ),
      } );
    } catch ( err ) {
      setSaveError( true );
      setTimeout( () => setSaveError( false ), TOAST_DURATION );
      setToastKey( ( k ) => k + 1 );
      setToast( {
        type: 'error',
        message:
          err.message ||
          __( 'Failed to save campaign.', 'mission-donation-platform' ),
      } );
    } finally {
      setIsSaving( false );
    }
  };

  if ( isLoading ) {
    return (
      <div className="mission-admin-page">
        <VStack
          spacing={ 6 }
          alignment="center"
          style={ { padding: '48px 0' } }
        >
          <Spinner />
        </VStack>
      </div>
    );
  }

  if ( error || ! campaign || ! formState ) {
    return (
      <div className="mission-admin-page">
        <VStack spacing={ 4 }>
          <a
            href={ campaignsUrl }
            style={ { color: BRAND_COLOR, textDecoration: 'none' } }
          >
            { __( 'Back to Campaigns', 'mission-donation-platform' ) }
          </a>
          <Text>
            { error ||
              __( 'Campaign not found.', 'mission-donation-platform' ) }
          </Text>
        </VStack>
      </div>
    );
  }

  return (
    <div className="mission-admin-page">
      <Toast key={ toastKey } notice={ toast } onDone={ clearToast } />
      <VStack spacing={ 0 }>
        { /* Breadcrumb */ }
        <a
          href={ campaignsUrl }
          style={ {
            color: BRAND_COLOR,
            textDecoration: 'none',
            fontSize: '13px',
            marginBottom: '16px',
            display: 'inline-flex',
            alignItems: 'center',
            gap: '4px',
          } }
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
            <path d="M9 11L4 7l5-4" />
          </svg>
          { __( 'Back to Campaigns', 'mission-donation-platform' ) }
        </a>

        <CampaignHero
          campaign={ campaign }
          hasCampaignPage={ formState.has_campaign_page }
        />
        <CampaignTabNav
          activeTab={ activeTab }
          onTabChange={ handleTabChange }
        />

        { activeTab === 'overview' && (
          <OverviewTab campaignId={ id } campaign={ campaign } />
        ) }
        { activeTab === 'edit-page' && (
          <EditPageTab
            campaign={ campaign }
            formState={ formState }
            setFormState={ setFormState }
            onSave={ handleSave }
            isSaving={ isSaving }
            saveSuccess={ saveSuccess }
            saveError={ saveError }
          />
        ) }
        { activeTab === 'settings' && (
          <SettingsTab
            campaign={ campaign }
            formState={ formState }
            setFormState={ setFormState }
            onSave={ handleSave }
            isSaving={ isSaving }
            saveSuccess={ saveSuccess }
            saveError={ saveError }
          />
        ) }
      </VStack>
    </div>
  );
}
