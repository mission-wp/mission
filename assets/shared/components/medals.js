/**
 * Gold / silver / bronze medal SVGs for ranked leaderboard editors
 * (Top Donors, Top Fundraisers, Top Teams). The matching markup is rendered
 * server-side in each block's index.php; this is the editor-preview copy.
 */

export const MEDAL_SVGS = [
  <svg key="gold" width="20" height="20" viewBox="0 0 24 24" fill="none">
    <circle
      cx="12"
      cy="9"
      r="7"
      fill="#D4A843"
      stroke="#C4962F"
      strokeWidth="1"
    />
    <circle
      cx="12"
      cy="9"
      r="5"
      fill="none"
      stroke="#E8C96A"
      strokeWidth="0.75"
      opacity="0.6"
    />
    <text
      x="12"
      y="12.5"
      textAnchor="middle"
      fontSize="8"
      fontWeight="700"
      fill="#7A5C1F"
    >
      1
    </text>
    <path
      d="M7.5 15L6 22l6-3 6 3-1.5-7"
      fill="#D4A843"
      stroke="#C4962F"
      strokeWidth="0.75"
      strokeLinejoin="round"
    />
  </svg>,
  <svg key="silver" width="20" height="20" viewBox="0 0 24 24" fill="none">
    <circle
      cx="12"
      cy="9"
      r="7"
      fill="#B0B4BC"
      stroke="#9CA0A8"
      strokeWidth="1"
    />
    <circle
      cx="12"
      cy="9"
      r="5"
      fill="none"
      stroke="#D0D4DC"
      strokeWidth="0.75"
      opacity="0.6"
    />
    <text
      x="12"
      y="12.5"
      textAnchor="middle"
      fontSize="8"
      fontWeight="700"
      fill="#5C5F66"
    >
      2
    </text>
    <path
      d="M7.5 15L6 22l6-3 6 3-1.5-7"
      fill="#B0B4BC"
      stroke="#9CA0A8"
      strokeWidth="0.75"
      strokeLinejoin="round"
    />
  </svg>,
  <svg key="bronze" width="20" height="20" viewBox="0 0 24 24" fill="none">
    <circle
      cx="12"
      cy="9"
      r="7"
      fill="#C68E5B"
      stroke="#B07A48"
      strokeWidth="1"
    />
    <circle
      cx="12"
      cy="9"
      r="5"
      fill="none"
      stroke="#DAA872"
      strokeWidth="0.75"
      opacity="0.6"
    />
    <text
      x="12"
      y="12.5"
      textAnchor="middle"
      fontSize="8"
      fontWeight="700"
      fill="#6B4420"
    >
      3
    </text>
    <path
      d="M7.5 15L6 22l6-3 6 3-1.5-7"
      fill="#C68E5B"
      stroke="#B07A48"
      strokeWidth="0.75"
      strokeLinejoin="round"
    />
  </svg>,
];
