import SubscriptionList from './subscriptions/SubscriptionList';
import SubscriptionDetail from './subscriptions/SubscriptionDetail';

export default function Subscriptions() {
  const params = new URLSearchParams( window.location.search );
  const subscriptionId = params.get( 'subscription_id' );

  if ( subscriptionId ) {
    return <SubscriptionDetail id={ Number( subscriptionId ) } />;
  }

  return <SubscriptionList />;
}
