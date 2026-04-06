import TransactionList from './transactions/TransactionList';
import TransactionDetail from './transactions/TransactionDetail';

export default function Transactions() {
  const params = new URLSearchParams( window.location.search );
  const transactionId = params.get( 'transaction_id' );

  if ( transactionId ) {
    return <TransactionDetail id={ Number( transactionId ) } />;
  }

  return <TransactionList />;
}
