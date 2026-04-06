function getPageNumbers( current, total ) {
  if ( total <= 7 ) {
    return Array.from( { length: total }, ( _, i ) => i + 1 );
  }
  const pages = new Set( [ 1, total, current, current - 1, current + 1 ] );
  const sorted = [ ...pages ]
    .filter( ( p ) => p >= 1 && p <= total )
    .sort( ( a, b ) => a - b );
  const result = [];
  for ( let i = 0; i < sorted.length; i++ ) {
    if ( i > 0 && sorted[ i ] - sorted[ i - 1 ] > 1 ) {
      result.push( '...' );
    }
    result.push( sorted[ i ] );
  }
  return result;
}

export default function Pagination( {
  currentPage,
  totalPages,
  totalItems,
  perPage,
  onChange,
} ) {
  const start = ( currentPage - 1 ) * perPage + 1;
  const end = Math.min( currentPage * perPage, totalItems );

  return (
    <div className="mission-pagination">
      <span className="mission-pagination__summary">
        { `${ start }\u2013${ end } of ${ totalItems }` }
      </span>
      <div className="mission-pagination__pages">
        <button
          type="button"
          className="mission-pagination__btn"
          disabled={ currentPage === 1 }
          onClick={ () => onChange( currentPage - 1 ) }
        >
          { '\u2039' }
        </button>
        { getPageNumbers( currentPage, totalPages ).map( ( page, i ) =>
          page === '...' ? (
            <span
              key={ `ellipsis-${ i }` }
              className="mission-pagination__ellipsis"
            >
              &hellip;
            </span>
          ) : (
            <button
              type="button"
              key={ page }
              className={ `mission-pagination__btn${
                page === currentPage ? ' is-active' : ''
              }` }
              onClick={ () => onChange( page ) }
            >
              { page }
            </button>
          )
        ) }
        <button
          type="button"
          className="mission-pagination__btn"
          disabled={ currentPage === totalPages }
          onClick={ () => onChange( currentPage + 1 ) }
        >
          { '\u203A' }
        </button>
      </div>
    </div>
  );
}
