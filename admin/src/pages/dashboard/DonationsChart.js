import { useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatAmount, getCurrencySymbol } from '@shared/currency';
import EmptyState from '../../components/EmptyState';

const ChartLineIcon = () => (
  <svg
    width="40"
    height="40"
    viewBox="0 0 40 40"
    fill="none"
    stroke="currentColor"
    strokeWidth="1.5"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    <polyline points="6,30 14,18 22,24 34,10" />
  </svg>
);

/**
 * Format a date or time string as a short label.
 *
 * Handles both "Y-m-d" (e.g. "Feb 1") and "HH:00" (e.g. "2 PM") formats.
 *
 * @param {string} dateStr Date string in Y-m-d or HH:00 format.
 */
function formatDateLabel( dateStr ) {
  // Hourly format: "HH:00".
  if ( dateStr.includes( ':' ) ) {
    const hour = parseInt( dateStr.split( ':' )[ 0 ], 10 );
    if ( hour === 0 ) {
      return '12 AM';
    }
    if ( hour === 12 ) {
      return '12 PM';
    }
    return hour > 12 ? `${ hour - 12 } PM` : `${ hour } AM`;
  }

  const d = new Date( dateStr + 'T00:00:00' );
  return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
}

/**
 * Draw the donations area chart on a canvas element.
 *
 * @param {HTMLCanvasElement} canvas         Canvas element to draw on.
 * @param {Array}             data           Chart data points.
 * @param {string}            currencySymbol Currency symbol for labels.
 * @param {number|null}       hoverIndex     Index of the hovered data point, or null.
 */
function drawChart( canvas, data, currencySymbol, hoverIndex ) {
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.parentElement.getBoundingClientRect();
  canvas.width = rect.width * dpr;
  canvas.height = rect.height * dpr;
  canvas.style.width = rect.width + 'px';
  canvas.style.height = rect.height + 'px';

  const ctx = canvas.getContext( '2d' );
  ctx.scale( dpr, dpr );

  const w = rect.width;
  const h = rect.height;
  const pad = { top: 20, right: 20, bottom: 40, left: 52 };
  const chartW = w - pad.left - pad.right;
  const chartH = h - pad.top - pad.bottom;

  // Need at least 2 points to draw a chart.
  if ( data.length < 2 ) {
    return;
  }

  // Convert minor units to major for display.
  const amounts = data.map( ( d ) => d.amount / 100 );
  const maxVal = Math.max(
    Math.ceil( Math.max( ...amounts ) / 100 ) * 100,
    100
  );
  const minVal = 0;

  // Grid lines & Y labels.
  ctx.font = '11px -apple-system, BlinkMacSystemFont, sans-serif';
  ctx.textAlign = 'right';
  const gridSteps = 4;
  for ( let i = 0; i <= gridSteps; i++ ) {
    const val = minVal + ( maxVal - minVal ) * ( i / gridSteps );
    const y = pad.top + chartH - ( i / gridSteps ) * chartH;
    ctx.strokeStyle = '#eee9e3';
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo( pad.left, y );
    ctx.lineTo( w - pad.right, y );
    ctx.stroke();
    ctx.fillStyle = '#9b9ba8';
    ctx.fillText( currencySymbol + val.toFixed( 0 ), pad.left - 8, y + 4 );
  }

  // X labels.
  ctx.textAlign = 'center';
  const labelStep = Math.ceil( data.length / 7 );
  data.forEach( ( d, i ) => {
    if ( i % labelStep === 0 || i === data.length - 1 ) {
      const x = pad.left + ( i / ( data.length - 1 ) ) * chartW;
      ctx.fillStyle = '#9b9ba8';
      ctx.fillText( formatDateLabel( d.date ), x, h - pad.bottom + 20 );
    }
  } );

  // Plot points.
  const points = data.map( ( d, i ) => ( {
    x: pad.left + ( i / ( data.length - 1 ) ) * chartW,
    y:
      pad.top +
      chartH -
      ( ( amounts[ i ] - minVal ) / ( maxVal - minVal ) ) * chartH,
  } ) );

  // Vertical hover line.
  if ( hoverIndex !== null && hoverIndex >= 0 && hoverIndex < points.length ) {
    const hx = points[ hoverIndex ].x;
    ctx.strokeStyle = '#e0ddd8';
    ctx.lineWidth = 1;
    ctx.setLineDash( [ 4, 3 ] );
    ctx.beginPath();
    ctx.moveTo( hx, pad.top );
    ctx.lineTo( hx, pad.top + chartH );
    ctx.stroke();
    ctx.setLineDash( [] );
  }

  // Gradient fill.
  const gradient = ctx.createLinearGradient( 0, pad.top, 0, pad.top + chartH );
  gradient.addColorStop( 0, 'rgba(47, 163, 107, 0.15)' );
  gradient.addColorStop( 1, 'rgba(47, 163, 107, 0.01)' );

  ctx.beginPath();
  ctx.moveTo( points[ 0 ].x, pad.top + chartH );
  points.forEach( ( p ) => ctx.lineTo( p.x, p.y ) );
  ctx.lineTo( points[ points.length - 1 ].x, pad.top + chartH );
  ctx.closePath();
  ctx.fillStyle = gradient;
  ctx.fill();

  // Smooth bezier line.
  ctx.beginPath();
  ctx.moveTo( points[ 0 ].x, points[ 0 ].y );
  for ( let i = 1; i < points.length; i++ ) {
    const prev = points[ i - 1 ];
    const curr = points[ i ];
    const cpx = ( prev.x + curr.x ) / 2;
    ctx.bezierCurveTo( cpx, prev.y, cpx, curr.y, curr.x, curr.y );
  }
  ctx.strokeStyle = '#2fa36b';
  ctx.lineWidth = 2.5;
  ctx.stroke();

  // Dots.
  points.forEach( ( p, i ) => {
    const isHovered = i === hoverIndex;
    const outerR = isHovered ? 5 : 3;
    const innerR = isHovered ? 2.5 : 1.5;

    ctx.beginPath();
    ctx.arc( p.x, p.y, outerR, 0, Math.PI * 2 );
    ctx.fillStyle = '#2fa36b';
    ctx.fill();
    ctx.beginPath();
    ctx.arc( p.x, p.y, innerR, 0, Math.PI * 2 );
    ctx.fillStyle = '#fff';
    ctx.fill();
  } );

  // Tooltip.
  if ( hoverIndex !== null && hoverIndex >= 0 && hoverIndex < points.length ) {
    const p = points[ hoverIndex ];
    const datum = data[ hoverIndex ];
    const amountStr = formatAmount( datum.amount );
    const dateStr = formatDateLabel( datum.date );
    const label = `${ amountStr }  ·  ${ dateStr }`;

    ctx.font = '600 12px -apple-system, BlinkMacSystemFont, sans-serif';
    const textWidth = ctx.measureText( label ).width;
    const tooltipW = textWidth + 20;
    const tooltipH = 32;
    const tooltipRadius = 8;

    // Position above the point; flip below if clipped at top.
    let tx = p.x - tooltipW / 2;
    const gap = 12;
    const ty = p.y - tooltipH - gap >= 0 ? p.y - tooltipH - gap : p.y + gap;

    // Clamp horizontally.
    if ( tx < pad.left ) {
      tx = pad.left;
    }
    if ( tx + tooltipW > w - pad.right ) {
      tx = w - pad.right - tooltipW;
    }

    // Tooltip background.
    ctx.fillStyle = '#1e1e1e';
    ctx.beginPath();
    ctx.roundRect( tx, ty, tooltipW, tooltipH, tooltipRadius );
    ctx.fill();

    // Tooltip text.
    ctx.fillStyle = '#fff';
    ctx.textAlign = 'center';
    ctx.fillText( label, tx + tooltipW / 2, ty + tooltipH / 2 + 4.5 );
  }
}

const EMPTY_HINTS = {
  today: __(
    'No donations have come in today.',
    'missionwp-donation-platform'
  ),
  week: __( 'No donations this week.', 'missionwp-donation-platform' ),
  month: __( 'No donations this month.', 'missionwp-donation-platform' ),
};

export default function DonationsChart( {
  chartData,
  isLoading,
  period,
  periodLabel,
} ) {
  const canvasRef = useRef( null );
  const hoverIndexRef = useRef( null );
  const currencySymbol = getCurrencySymbol();

  const hasData = chartData && chartData.some( ( d ) => d.amount > 0 );

  const redraw = useCallback( () => {
    if ( canvasRef.current && chartData && hasData ) {
      drawChart(
        canvasRef.current,
        chartData,
        currencySymbol,
        hoverIndexRef.current
      );
    }
  }, [ chartData, hasData, currencySymbol ] );

  useEffect( () => {
    if ( ! canvasRef.current || ! hasData || isLoading ) {
      return;
    }

    const canvas = canvasRef.current;

    // Draw after a frame to ensure layout is settled.
    const raf = window.requestAnimationFrame( () => redraw() );

    // Redraw on resize.
    const observer = new window.ResizeObserver( () => redraw() );
    observer.observe( canvas.parentElement );

    // Mouse interaction.
    const handleMouseMove = ( e ) => {
      if ( ! chartData || chartData.length < 2 ) {
        return;
      }

      const rect = canvas.getBoundingClientRect();
      const mouseX = e.clientX - rect.left;
      const pad = { left: 52, right: 20 };
      const chartW = rect.width - pad.left - pad.right;

      // Find nearest data point by X position.
      let nearest = null;
      let nearestDist = Infinity;

      for ( let i = 0; i < chartData.length; i++ ) {
        const px = pad.left + ( i / ( chartData.length - 1 ) ) * chartW;
        const dist = Math.abs( mouseX - px );
        if ( dist < nearestDist ) {
          nearestDist = dist;
          nearest = i;
        }
      }

      // Only show tooltip when mouse is within the chart area.
      if ( mouseX < pad.left - 10 || mouseX > rect.width - pad.right + 10 ) {
        nearest = null;
      }

      if ( nearest !== hoverIndexRef.current ) {
        hoverIndexRef.current = nearest;
        redraw();
      }
    };

    const handleMouseLeave = () => {
      if ( hoverIndexRef.current !== null ) {
        hoverIndexRef.current = null;
        redraw();
      }
    };

    canvas.addEventListener( 'mousemove', handleMouseMove );
    canvas.addEventListener( 'mouseleave', handleMouseLeave );

    return () => {
      window.cancelAnimationFrame( raf );
      observer.disconnect();
      canvas.removeEventListener( 'mousemove', handleMouseMove );
      canvas.removeEventListener( 'mouseleave', handleMouseLeave );
    };
  }, [ chartData, hasData, isLoading, redraw ] );

  return (
    <div className="mission-dashboard-card">
      <div className="mission-dashboard-card__header">
        <h2>{ __( 'Donations Over Time', 'missionwp-donation-platform' ) }</h2>
        <span className="mission-dashboard-card__badge">{ periodLabel }</span>
      </div>

      { isLoading && (
        <div className="mission-chart-container">
          <div
            className="mission-skeleton"
            style={ {
              width: '100%',
              height: '100%',
              borderRadius: '8px',
              background: '#eee9e3',
            } }
          />
        </div>
      ) }
      { ! isLoading && ! hasData && (
        <EmptyState
          icon={ <ChartLineIcon /> }
          text={ __( 'No donations yet', 'missionwp-donation-platform' ) }
          hint={
            EMPTY_HINTS[ period ] ||
            __(
              'Donation trends will appear here once you receive your first gift.',
              'missionwp-donation-platform'
            )
          }
        />
      ) }
      { ! isLoading && hasData && (
        <div className="mission-chart-container">
          <canvas ref={ canvasRef } />
        </div>
      ) }
    </div>
  );
}
