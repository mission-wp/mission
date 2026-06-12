/* eslint-env jest */

import { buildLogMessage, buildDetailRows } from '../logs-utils';

describe( 'buildLogMessage', () => {
  beforeEach( () => {
    window.missiondpAdmin = { currency: 'USD' };
  } );

  afterEach( () => {
    delete window.missiondpAdmin;
  } );

  it( 'formats a completed donation with amount and donor', () => {
    const msg = buildLogMessage( {
      event: 'donation_completed',
      data: { amount: 5000, donor_name: 'Jane Doe' },
    } );
    expect( msg ).toBe(
      'Payment of <strong>$50.00</strong> completed for Jane Doe'
    );
  } );

  it( 'falls back to a generic message without an amount', () => {
    const msg = buildLogMessage( { event: 'donation_completed', data: {} } );
    expect( msg ).toBe( 'Donation completed' );
  } );

  it( 'includes the frequency suffix for recurring renewals', () => {
    const msg = buildLogMessage( {
      event: 'recurring_donation_processed',
      data: { amount: 2500, frequency: 'monthly', donor_name: 'Jane' },
    } );
    expect( msg ).toBe(
      'Recurring donation of <strong>$25.00/mo</strong> renewed for Jane'
    );
  } );

  it( 'formats webhook and email events', () => {
    expect(
      buildLogMessage( {
        event: 'webhook_received',
        data: { stripe_event_type: 'payment_intent.succeeded' },
      } )
    ).toBe( 'Webhook received: <strong>payment_intent.succeeded</strong>' );

    expect(
      buildLogMessage( {
        event: 'email_sent',
        data: { recipient: 'donor@example.com' },
      } )
    ).toBe( 'Email sent to <strong>donor@example.com</strong>' );
  } );

  it( 'pluralizes admin notification recipients', () => {
    const one = buildLogMessage( {
      event: 'admin_notification_sent',
      data: { notification_type: 'admin_new_donation', recipient_count: 1 },
    } );
    const two = buildLogMessage( {
      event: 'admin_notification_sent',
      data: { notification_type: 'admin_new_donation', recipient_count: 2 },
    } );
    expect( one ).toContain( 'sent to 1 recipient' );
    expect( one ).not.toContain( 'recipients' );
    expect( two ).toContain( 'sent to 2 recipients' );
  } );

  it( 'renders import results with type-specific nouns', () => {
    expect(
      buildLogMessage( {
        event: 'data_imported',
        data: { type: 'donors', imported: 1, updated: 0 },
      } )
    ).toBe( 'Imported <strong>1 donor</strong>' );

    expect(
      buildLogMessage( {
        event: 'data_imported',
        data: { type: 'tributes', imported: 1500, updated: 0 },
      } )
    ).toBe( 'Imported <strong>1,500 dedications</strong>' );
  } );

  it( 'prefixes import results with the actor when known', () => {
    const msg = buildLogMessage( {
      event: 'data_imported',
      data: { type: 'donors', imported: 3, updated: 2, actor_name: 'Nate' },
    } );
    expect( msg ).toBe(
      'Nate imported <strong>3</strong> and updated <strong>2 donors</strong>'
    );
  } );

  it( 'reports imports with no changes', () => {
    const msg = buildLogMessage( {
      event: 'data_imported',
      data: { type: 'donors', imported: 0, updated: 0 },
    } );
    expect( msg ).toBe( 'Import completed with no changes' );
  } );

  it( 'totals migration counts and names the source', () => {
    const msg = buildLogMessage( {
      event: 'data_migrated',
      data: {
        source: 'givewp',
        counts: { donors: 10, transactions: 20 },
      },
    } );
    expect( msg ).toBe( 'Migrated <strong>30</strong> records from GiveWP' );
  } );

  it( 'falls back to a generic source name for unknown migrations', () => {
    const msg = buildLogMessage( {
      event: 'data_migrated',
      data: { counts: {} },
    } );
    expect( msg ).toBe(
      'Migration from another plugin finished with no new records'
    );
  } );

  it( 'title-cases unknown events', () => {
    expect( buildLogMessage( { event: 'some_new_event', data: {} } ) ).toBe(
      'Some New Event'
    );
  } );

  it( 'formats settings updates with changed keys', () => {
    const msg = buildLogMessage( {
      event: 'settings_updated',
      data: { changed_keys: [ 'currency', 'org_name' ] },
    } );
    expect( msg ).toBe(
      'Settings updated: <strong>currency, org_name</strong>'
    );
  } );
} );

describe( 'buildDetailRows', () => {
  beforeEach( () => {
    window.missiondpAdmin = { currency: 'USD' };
  } );

  afterEach( () => {
    delete window.missiondpAdmin;
  } );

  it( 'builds donation rows and skips empty values', () => {
    const rows = buildDetailRows( {
      event: 'donation_completed',
      data: { amount: 5000, donor_name: 'Jane Doe', campaign_title: '' },
    } );
    expect( rows ).toEqual( [
      { label: 'Amount', value: '$50.00' },
      { label: 'Donor', value: 'Jane Doe' },
    ] );
  } );

  it( 'shows before and after values for settings changes', () => {
    const rows = buildDetailRows( {
      event: 'settings_updated',
      data: {
        changes: {
          test_mode: { from: true, to: false },
          org_name: { from: '', to: 'New Org' },
        },
      },
    } );
    expect( rows ).toEqual( [
      { label: 'test_mode', value: 'on → off' },
      { label: 'org_name', value: '(empty) → New Org' },
    ] );
  } );

  it( 'includes old and new amounts for subscription changes', () => {
    const rows = buildDetailRows( {
      event: 'subscription_amount_increased',
      data: { old_amount: 1000, new_amount: 2500, frequency: 'monthly' },
    } );
    expect( rows ).toEqual( [
      { label: 'Previous amount', value: '$10.00' },
      { label: 'New amount', value: '$25.00' },
      { label: 'Frequency', value: 'monthly' },
    ] );
  } );

  it( 'dumps all data keys for unknown events', () => {
    const rows = buildDetailRows( {
      event: 'mystery_event',
      data: { some_key: 'value', nested: { a: 1 } },
    } );
    expect( rows ).toEqual( [
      { label: 'some key', value: 'value' },
      { label: 'nested', value: '{"a":1}' },
    ] );
  } );

  it( 'returns no rows when there is no data', () => {
    expect( buildDetailRows( { event: 'plugin_activated' } ) ).toEqual( [] );
  } );
} );
