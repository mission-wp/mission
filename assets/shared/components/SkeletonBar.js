/**
 * Pulsing placeholder bar shown while data loads.
 *
 * The pulse animation comes from the .mission-skeleton class; this component
 * only sets the shape. Pass `style` to override display or alignment.
 *
 * @param {Object} props
 * @param {string} [props.width]  CSS width of the bar.
 * @param {string} [props.height] CSS height of the bar.
 * @param {Object} [props.style]  Extra inline styles merged over the defaults.
 * @return {JSX.Element} The skeleton bar.
 */
export default function SkeletonBar( {
  width = '60%',
  height = '16px',
  style,
} ) {
  return (
    <span
      className="mission-skeleton"
      style={ {
        display: 'block',
        width,
        height,
        borderRadius: '4px',
        background: '#e2e4e9',
        ...style,
      } }
    />
  );
}
