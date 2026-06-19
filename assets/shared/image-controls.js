/**
 * Shared option lists for the image blocks (campaign/fundraiser/team).
 *
 * Kept separate from the editor components so any block can reuse them without
 * pulling in component code.
 */
import { __ } from '@wordpress/i18n';

export const ASPECT_RATIO_OPTIONS = [
  { label: __( 'Original', 'mission-donation-platform' ), value: '' },
  { label: __( 'Square - 1:1', 'mission-donation-platform' ), value: '1/1' },
  { label: __( 'Standard - 4:3', 'mission-donation-platform' ), value: '4/3' },
  { label: __( 'Portrait - 3:4', 'mission-donation-platform' ), value: '3/4' },
  { label: __( 'Classic - 3:2', 'mission-donation-platform' ), value: '3/2' },
  {
    label: __( 'Classic Portrait - 2:3', 'mission-donation-platform' ),
    value: '2/3',
  },
  { label: __( 'Wide - 16:9', 'mission-donation-platform' ), value: '16/9' },
  { label: __( 'Tall - 9:16', 'mission-donation-platform' ), value: '9/16' },
];

export const RESOLUTION_OPTIONS = [
  { label: __( 'Thumbnail', 'mission-donation-platform' ), value: 'thumbnail' },
  { label: __( 'Medium', 'mission-donation-platform' ), value: 'medium' },
  { label: __( 'Large', 'mission-donation-platform' ), value: 'large' },
  { label: __( 'Full Size', 'mission-donation-platform' ), value: 'full' },
];
