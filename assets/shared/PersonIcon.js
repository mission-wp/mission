/**
 * Generic person glyph used as a placeholder avatar in editor previews, where
 * there is no real supporter or member to show initials for.
 *
 * @param {Object} props
 * @param {number} [props.size] Pixel size of the glyph.
 * @return {Element} SVG element.
 */
export function PersonIcon( { size = 18 } ) {
  return (
    <svg
      width={ size }
      height={ size }
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <circle cx="12" cy="8" r="3.5" />
      <path d="M5.5 20c0-3.6 2.9-5.5 6.5-5.5s6.5 1.9 6.5 5.5" />
    </svg>
  );
}
